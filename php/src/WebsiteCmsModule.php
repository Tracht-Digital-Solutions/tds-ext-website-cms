<?php
declare(strict_types=1);

namespace Tds\Ext\WebsiteCms;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\WebsiteCms\Domain\CmsRepository;
use Tds\Ext\WebsiteCms\Service\RebuildTrigger;
use Tds\Panel\Contract\AbstractModule;
use Tds\Panel\Contract\PermissionDef;
use Tds\Panel\Contract\UserContext;

/**
 * Backend Module for the Website-CMS (checkpoint-1: site registry + per-(site,
 * section, lang) JSON content-block CRUD + the sites widget summary). Auth via
 * the core UserContext (`website:read`/`website:write`, admins bypass); data via
 * the core PDO. A save triggering a static-site rebuild (workflow_dispatch) lands
 * in a later checkpoint.
 */
final class WebsiteCmsModule extends AbstractModule
{
    private const LANGS = ['de', 'en'];

    public function id(): string
    {
        return 'website-cms';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('website:read', 'Website-Inhalte ansehen', 'website-cms'),
            new PermissionDef('website:write', 'Website-Inhalte bearbeiten', 'website-cms'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(CmsRepository::class)) {
            $c->set(CmsRepository::class, static fn ($c) => new CmsRepository($c->get(PDO::class)));
        }
        if ($c !== null && !$c->has(RebuildTrigger::class)) {
            $c->set(RebuildTrigger::class, static fn () => RebuildTrigger::fromEnv());
        }

        $app->get('/cms/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['sites' => $c->get(CmsRepository::class)->siteCount()]);
        });

        $app->get('/cms/sites', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['sites' => $c->get(CmsRepository::class)->sites()]);
        });

        $app->post('/cms/sites', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $key = strtolower(trim((string) ($body['site_key'] ?? '')));
            $name = trim((string) ($body['name'] ?? ''));
            if (preg_match('/^[a-z0-9-]{2,64}$/', $key) !== 1 || $name === '') {
                return self::json($res, ['error' => 'site_key (kebab) and name are required'], 422);
            }
            $repo = $c->get(CmsRepository::class);
            if ($repo->siteKeyExists($key)) {
                return self::json($res, ['error' => 'site_key already exists'], 409);
            }
            return self::json($res, ['id' => $repo->createSite($key, $name)], 201);
        });

        $app->get('/cms/{site:[a-z0-9-]+}/blocks', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            return self::json($res, ['blocks' => $repo->blocks((int) $site['id'])]);
        });

        $app->get('/cms/{site:[a-z0-9-]+}/blocks/{key:[a-z0-9_-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            $lang = self::lang($req->getQueryParams()['lang'] ?? 'de');
            return self::json($res, ['value' => $repo->getBlock((int) $site['id'], (string) $args['key'], $lang), 'lang' => $lang]);
        });

        $app->put('/cms/{site:[a-z0-9-]+}/blocks/{key:[a-z0-9_-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            if (!array_key_exists('value', $body) || !is_array($body['value'])) {
                return self::json($res, ['error' => 'value (object) is required'], 422);
            }
            $lang = self::lang($body['lang'] ?? 'de');
            $repo->putBlock((int) $site['id'], (string) $args['key'], $lang, json_encode($body['value'], JSON_THROW_ON_ERROR));
            self::fireRebuild($c->get(RebuildTrigger::class), $site, 'block ' . (string) $args['key'] . ' saved');
            return self::json($res, ['ok' => true]);
        });

        // Set a site's rebuild target (repo/workflow); blank clears it.
        $app->put('/cms/sites/{site:[a-z0-9-]+}/rebuild-config', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $repoName = trim((string) ($body['rebuild_repo'] ?? ''));
            $workflow = trim((string) ($body['rebuild_workflow'] ?? ''));
            if ($repoName !== '' && preg_match('#^[\w.-]+/[\w.-]+$#', $repoName) !== 1) {
                return self::json($res, ['error' => 'rebuild_repo must be "owner/name"'], 422);
            }
            $repo->updateSiteRebuild((int) $site['id'], $repoName !== '' ? $repoName : null, $workflow !== '' ? $workflow : null);
            return self::json($res, ['ok' => true]);
        });

        // Manually fire a site's rebuild ("Jetzt neu bauen").
        $app->post('/cms/sites/{site:[a-z0-9-]+}/rebuild', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            $trigger = $c->get(RebuildTrigger::class);
            if (!$trigger->isConfigured()) {
                return self::json($res, ['error' => 'Rebuild token not configured'], 503);
            }
            if (trim((string) ($site['rebuild_repo'] ?? '')) === '') {
                return self::json($res, ['error' => 'No rebuild repo configured for this site'], 422);
            }
            self::fireRebuild($trigger, $site, 'manual rebuild');
            return self::json($res, ['ok' => true], 202);
        });

        $app->delete('/cms/{site:[a-z0-9-]+}/blocks/{key:[a-z0-9_-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'website:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CmsRepository::class);
            $site = $repo->findSite((string) $args['site']);
            if ($site === null) {
                return self::json($res, ['error' => 'Site not found'], 404);
            }
            $repo->deleteBlock((int) $site['id'], (string) $args['key'], self::lang($req->getQueryParams()['lang'] ?? 'de'));
            self::fireRebuild($c->get(RebuildTrigger::class), $site, 'block ' . (string) $args['key'] . ' deleted');
            return self::json($res, ['ok' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $site */
    private static function fireRebuild(RebuildTrigger $trigger, array $site, string $reason): void
    {
        $trigger->trigger(
            isset($site['rebuild_repo']) ? (string) $site['rebuild_repo'] : null,
            isset($site['rebuild_workflow']) ? (string) $site['rebuild_workflow'] : null,
            $reason,
        );
    }

    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function lang(mixed $value): string
    {
        $v = is_string($value) ? strtolower($value) : '';
        return in_array($v, self::LANGS, true) ? $v : 'de';
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
