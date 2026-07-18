import { useEffect, useState } from "react";

interface Site {
  id: number;
  site_key: string;
  name: string;
  rebuild_repo?: string | null;
  rebuild_workflow?: string | null;
  updated_at: string;
}

interface BlockMeta {
  section_key: string;
  lang: string;
  updated_at: string;
}

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });

/**
 * Website-CMS: managed-sites list + add-site form (CP1) and the per-site content
 * block editor (CP2) — list a site's section blocks and edit each block's JSON
 * (one object per section × language), saved via PUT. A save-triggered static-
 * site rebuild lands in a later checkpoint.
 */
export default function SitesList() {
  const [sites, setSites] = useState<Site[] | null>(null);
  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<Site | null>(null);

  const load = () =>
    api("/cms/sites")
      .then((r) => (r.ok ? r.json() : { sites: [] }))
      .then((d) => setSites(d.sites ?? []))
      .catch(() => setSites([]));

  useEffect(() => {
    load();
  }, []);

  const create = async () => {
    if (!/^[a-z0-9-]{2,64}$/.test(key) || name.trim() === "") return;
    const res = await api("/cms/sites", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ site_key: key, name }),
    });
    if (res.ok) {
      setKey("");
      setName("");
      load();
    }
  };

  if (selected) {
    return <SiteEditor site={selected} onBack={() => setSelected(null)} />;
  }

  return (
    <div className="cms-sites">
      <form className="cms-sites__form" onSubmit={(e) => { e.preventDefault(); create(); }}>
        <input value={key} onChange={(e) => setKey(e.target.value)} placeholder="site-key (kebab)" required />
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" required />
        <button type="submit">Website hinzufügen</button>
      </form>

      {sites === null ? (
        <p>Wird geladen …</p>
      ) : sites.length === 0 ? (
        <p>Noch keine Websites angelegt.</p>
      ) : (
        <ul className="cms-sites__list">
          {sites.map((s) => (
            <li key={s.id}>
              <button type="button" onClick={() => setSelected(s)}>
                <strong>{s.name}</strong> <code>{s.site_key}</code>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function SiteEditor({ site, onBack }: { site: Site; onBack: () => void }) {
  const [blocks, setBlocks] = useState<BlockMeta[] | null>(null);
  const [sectionKey, setSectionKey] = useState("");
  const [lang, setLang] = useState("de");
  const [json, setJson] = useState("{}");
  const [status, setStatus] = useState<string | null>(null);
  const [rebuildRepo, setRebuildRepo] = useState(site.rebuild_repo ?? "");
  const [rebuildWorkflow, setRebuildWorkflow] = useState(site.rebuild_workflow ?? "dev.yml");
  const [rebuildStatus, setRebuildStatus] = useState<string | null>(null);

  const loadBlocks = () =>
    api(`/cms/${site.site_key}/blocks`)
      .then((r) => (r.ok ? r.json() : { blocks: [] }))
      .then((d) => setBlocks(d.blocks ?? []))
      .catch(() => setBlocks([]));

  useEffect(() => {
    loadBlocks();
  }, []);

  const openBlock = async (key: string, l: string) => {
    setSectionKey(key);
    setLang(l);
    setStatus(null);
    const res = await api(`/cms/${site.site_key}/blocks/${key}?lang=${l}`);
    const d = res.ok ? await res.json() : { value: null };
    setJson(JSON.stringify(d.value ?? {}, null, 2));
  };

  const save = async () => {
    if (!/^[a-z0-9_-]{1,64}$/.test(sectionKey)) {
      setStatus("Ungültiger Section-Key.");
      return;
    }
    let value: unknown;
    try {
      value = JSON.parse(json);
    } catch {
      setStatus("Ungültiges JSON.");
      return;
    }
    if (typeof value !== "object" || value === null || Array.isArray(value)) {
      setStatus("Wert muss ein JSON-Objekt sein.");
      return;
    }
    const res = await api(`/cms/${site.site_key}/blocks/${sectionKey}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ value, lang }),
    });
    setStatus(res.ok ? "Gespeichert (Rebuild ausgelöst, falls konfiguriert)." : `Fehler (HTTP ${res.status}).`);
    if (res.ok) loadBlocks();
  };

  const saveRebuildConfig = async () => {
    const res = await api(`/cms/sites/${site.site_key}/rebuild-config`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rebuild_repo: rebuildRepo.trim(), rebuild_workflow: rebuildWorkflow.trim() }),
    });
    setRebuildStatus(res.ok ? "Rebuild-Konfiguration gespeichert." : `Fehler (HTTP ${res.status}).`);
  };

  const rebuildNow = async () => {
    setRebuildStatus("Rebuild wird ausgelöst …");
    const res = await api(`/cms/sites/${site.site_key}/rebuild`, { method: "POST" });
    if (res.ok) {
      setRebuildStatus("Rebuild ausgelöst.");
    } else if (res.status === 503) {
      setRebuildStatus("Kein Rebuild-Token konfiguriert (WEBSITE_REBUILD_TOKEN).");
    } else if (res.status === 422) {
      setRebuildStatus("Für diese Website ist kein Repository hinterlegt.");
    } else {
      setRebuildStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  return (
    <div className="cms-editor">
      <button type="button" onClick={onBack}>← Websites</button>
      <h2>{site.name}</h2>

      <div className="cms-editor__blocks">
        <h3>Sektionen</h3>
        {blocks === null ? (
          <p>Wird geladen …</p>
        ) : blocks.length === 0 ? (
          <p>Noch keine Blöcke.</p>
        ) : (
          <ul>
            {blocks.map((b) => (
              <li key={`${b.section_key}-${b.lang}`}>
                <button type="button" onClick={() => openBlock(b.section_key, b.lang)}>
                  <code>{b.section_key}</code> <span className="chip chip--neutral">{b.lang}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="cms-editor__form">
        <h3>Block bearbeiten</h3>
        <div className="flex gap-2">
          <input value={sectionKey} onChange={(e) => setSectionKey(e.target.value)} placeholder="section-key (z. B. faq)" />
          <select value={lang} onChange={(e) => setLang(e.target.value)}>
            <option value="de">de</option>
            <option value="en">en</option>
          </select>
        </div>
        <textarea
          className="cms-editor__json"
          value={json}
          onChange={(e) => setJson(e.target.value)}
          rows={14}
          spellCheck={false}
        />
        {status ? <p className="status-pill status-pill--info">{status}</p> : null}
        <button type="button" onClick={save}>Speichern</button>
      </div>

      <div className="cms-editor__rebuild">
        <h3>Rebuild-Konfiguration</h3>
        <p className="cms-editor__hint">
          Repository (<code>owner/name</code>) und Workflow-Datei, die ein Speichern neu baut.
          Der Token wird serverseitig über <code>WEBSITE_REBUILD_TOKEN</code> bereitgestellt.
        </p>
        <div className="flex gap-2">
          <input
            value={rebuildRepo}
            onChange={(e) => setRebuildRepo(e.target.value)}
            placeholder="Tracht-Digital-Solutions/tds-landingpage"
          />
          <input
            value={rebuildWorkflow}
            onChange={(e) => setRebuildWorkflow(e.target.value)}
            placeholder="dev.yml"
          />
        </div>
        {rebuildStatus ? <p className="status-pill status-pill--info">{rebuildStatus}</p> : null}
        <div className="flex gap-2">
          <button type="button" onClick={saveRebuildConfig}>Konfiguration speichern</button>
          <button type="button" onClick={rebuildNow}>Jetzt neu bauen</button>
        </div>
      </div>
    </div>
  );
}
