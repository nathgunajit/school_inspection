// Builds "Product Details/School_Inspection_Product_Details.docx": one Word file with every product document.
// Usage (from tools/docs):  npm install  &&  npm run build
// Part A = technical documentation, Part B = project documents, Part C = appendix listing Requirements/.
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');
const D = require('docx');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, Table, TableRow, TableCell, WidthType, ShadingType,
  AlignmentType, LevelFormat, PageBreak, TableOfContents, ImageRun, Footer, Header, PageNumber, BorderStyle,
} = D;

const ROOT = process.argv[2] || path.resolve(__dirname, '..', '..');
const DOCDIR = path.join(ROOT, 'Product Details');
const OUT = path.join(DOCDIR, 'School_Inspection_Product_Details.docx');
const PARTS = [
  { key: 'A', title: 'Part A: Technical Documentation', intro: 'Prepared from a read-only review of the source code, the live database schema and the UI.', files: [
    'Database_Architecture_and_Schema.md', 'Project_File_Structure_and_Technical_Architecture.md', 'School_Inspection_Mechanism.md',
    'User_Roles_and_Permissions.md', 'UI_Pages_and_Application_Modules.md', 'System_Architecture_and_Workflow.md',
    'Technical_Audit_and_Recommendations.md', 'Future_Recommendations_Gaps_and_Roadmap.md'] },
  { key: 'B', title: 'Part B: Project Documents', intro: 'Functional and operational documents kept up to date with each modification of the application.', files: [
    'PROJECT_DOCUMENT.md', 'INSPECTION_FORM_DOCUMENT.md', 'NEW_MODULES_PLAN.md', 'DB_INDEXING.md', 'LOAD_TEST.md', 'BROWSER_SUPPORT.md'] },
];
const CURRENT_MD_DIR = path.join(ROOT, 'Product Details');
const BLUE = '1565C0', GREEN = '1E9B4B', ORANGE = 'F39223', GREY = '64748B';
const FONT = 'Calibri', MONO = 'Consolas';
const PAGE_W = 11906, MARGIN = 1000, CONTENT_W = PAGE_W - 2 * MARGIN; // A4, DXA

// ---------- inline markdown -> TextRuns ----------
function decode(s) {
  return s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}
function inlineRuns(tokens, base = {}) {
  const runs = [];
  for (const t of tokens || []) {
    switch (t.type) {
      case 'strong': runs.push(...inlineRuns(t.tokens, { ...base, bold: true })); break;
      case 'em': runs.push(...inlineRuns(t.tokens, { ...base, italics: true })); break;
      case 'codespan': runs.push(new TextRun({ text: decode(t.text), font: MONO, size: (base.size || 20) - 2, color: '0F3D7A', ...pick(base) })); break;
      case 'link': runs.push(...inlineRuns(t.tokens, { ...base, color: BLUE, underline: {} })); break;
      case 'br': runs.push(new TextRun({ text: '', break: 1 })); break;
      case 'escape': case 'text':
        if (t.tokens && t.tokens.length) runs.push(...inlineRuns(t.tokens, base));
        else runs.push(new TextRun({ text: decode(t.text), font: FONT, ...base }));
        break;
      case 'html': if (/^<br\s*\/?>$/i.test(t.text.trim())) runs.push(new TextRun({ text: '', break: 1 })); break;
      case 'image': runs.push(...imageRuns(t.href, t.text)); break;
      default: if (t.text) runs.push(new TextRun({ text: decode(t.text), font: FONT, ...base }));
    }
  }
  return runs;
}
function pick(b) { const o = {}; if (b.bold) o.bold = true; if (b.italics) o.italics = true; return o; }

// ---------- mermaid -> readable steps ----------
function mermaidToParas(src) {
  const lines = src.split('\n').map(l => l.trim()).filter(Boolean);
  const kind = lines[0].split(/\s/)[0];
  const labels = {};
  const clean = s => s.replace(/<br\/?>/g, ' ').replace(/^["']|["']$/g, '').trim();
  const nodeRe = /([A-Za-z0-9_]+)(?:\[\[(.*?)\]\]|\[\((.*?)\)\]|\(\[(.*?)\]\)|\[(.*?)\]|\(\((.*?)\)\)|\((.*?)\)|\{(.*?)\})/g;
  const lab = m => m.slice(2).find(x => x !== undefined) || '';
  for (const l of lines) { let m; while ((m = nodeRe.exec(l))) labels[m[1]] = clean(lab(m)); }
  const name = id => labels[id] || id;
  const items = [];
  const title = { flowchart: 'Flow', stateDiagram: 'States and transitions', 'stateDiagram-v2': 'States and transitions', sequenceDiagram: 'Sequence of messages', erDiagram: 'Relationships', gantt: 'Schedule' }[kind] || 'Diagram';
  for (const l of lines.slice(1)) {
    if (/^(subgraph|end$|classDef|class |participant|title|dateFormat|direction|loop|alt|else)/.test(l)) {
      if (/^section /.test(l)) items.push({ head: l.replace(/^section /, '') });
      if (/^(loop|alt|else) /.test(l)) items.push({ head: l });
      continue;
    }
    if (/^section /.test(l)) { items.push({ head: l.replace(/^section /, '') }); continue; }
    if (kind === 'gantt') { const m = l.match(/^(.*?)\s*:\s*\w+,\s*([\d-]+),\s*(\w+)/); if (m) items.push({ text: `${m[1].trim()} (from ${m[2]}, ${m[3]})` }); continue; }
    if (kind === 'erDiagram') { const m = l.match(/^(\w+)\s+(\S+)\s+(\w+)\s*:\s*(.*)$/); if (m) { const card = m[2].includes('o{') || m[2].includes('|{') ? 'one-to-many' : (m[2].includes('o|') || m[2].includes('||--||') ? 'one-to-one' : 'related'); items.push({ text: `${m[1]} → ${m[3]} (${card})${clean(m[4]) ? ': ' + clean(m[4]) : ''}` }); } continue; }
    if (kind === 'sequenceDiagram') { const m = l.match(/^(\w+)\s*-[-]?>>?\s*(\w+)\s*:\s*(.*)$/); if (m) items.push({ text: `${m[1]} → ${m[2]}: ${clean(m[3])}` }); continue; }
    if (kind.startsWith('stateDiagram')) { const m = l.match(/^(\[\*\]|\w+)\s*-->\s*(\[\*\]|\w+)\s*(?::\s*(.*))?$/); if (m) { const s = x => x === '[*]' ? '(start/end)' : x; items.push({ text: `${s(m[1])} → ${s(m[2])}${m[3] ? ': ' + m[3] : ''}` }); } continue; }
    // flowchart edges (possibly chained)
    const stripped = l.replace(nodeRe, (_, id) => id);
    const parts = stripped.split(/\s*(-->|-\.->|==>|---|-\.-|--\s[^-]+?\s-->|-- [^-]+ -->|-\. [^.]+ \.->)\s*/);
    if (parts.length >= 3) {
      for (let i = 0; i + 2 < parts.length; i += 2) {
        let edge = parts[i + 1]; let to = parts[i + 2]; let lab = '';
        const pipe = to.match(/^\|([^|]*)\|\s*(.*)$/); if (pipe) { lab = pipe[1]; to = pipe[2]; }
        const inner = edge.match(/^(?:--|-\.)\s(.+?)\s(?:-->|\.->)$/); if (inner) lab = inner[1];
        const froms = parts[i].split('&').map(s => s.trim());
        for (const f of froms) items.push({ text: `${name(f)} → ${name(to.trim())}${lab ? ' (' + clean(lab) + ')' : ''}${edge.includes('.') ? ' [optional]' : ''}` });
      }
    }
  }
  const out = [new Paragraph({ children: [new TextRun({ text: `Diagram (${title}). The drawn diagram is in the Markdown source file named under the chapter title.`, italics: true, color: GREY, size: 18, font: FONT })], spacing: { before: 80, after: 60 } })];
  let n = 0;
  for (const it of items.slice(0, 80)) {
    if (it.head) { out.push(new Paragraph({ children: [new TextRun({ text: it.head, bold: true, size: 19, font: FONT, color: BLUE })], spacing: { before: 60, after: 20 } })); continue; }
    n++;
    out.push(new Paragraph({ numbering: { reference: 'diag', level: 0 }, children: [new TextRun({ text: it.text, size: 19, font: FONT })], spacing: { after: 20 } }));
  }
  return out;
}

// ---------- tables ----------
function cellText(cell) { return (cell.text || '').replace(/`/g, ''); }
function buildTable(t) {
  const cols = t.header.length;
  const lens = t.header.map((h, i) => Math.max(cellText(h).length, ...t.rows.map(r => Math.min(60, cellText(r[i] || {}).length))));
  const weights = lens.map(l => Math.max(4, Math.sqrt(l + 2)));
  const total = weights.reduce((a, b) => a + b, 0);
  const widths = weights.map(w => Math.floor(CONTENT_W * w / total));
  widths[widths.length - 1] += CONTENT_W - widths.reduce((a, b) => a + b, 0);
  const size = cols >= 7 ? 15 : cols >= 5 ? 16 : 18;
  const border = { style: BorderStyle.SINGLE, size: 4, color: 'D0D7E2' };
  const borders = { top: border, bottom: border, left: border, right: border };
  const mk = (cell, i, head, zebra) => new TableCell({
    width: { size: widths[i], type: WidthType.DXA }, borders,
    shading: head ? { type: ShadingType.CLEAR, fill: BLUE, color: 'auto' } : (zebra ? { type: ShadingType.CLEAR, fill: 'F3F6FA', color: 'auto' } : undefined),
    margins: { top: 40, bottom: 40, left: 70, right: 70 },
    children: [new Paragraph({ children: inlineRuns(cell.tokens, head ? { bold: true, color: 'FFFFFF', size } : { size }) })],
  });
  return new Table({
    width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: widths,
    rows: [
      new TableRow({ tableHeader: true, children: t.header.map((c, i) => mk(c, i, true)) }),
      ...t.rows.map((r, ri) => new TableRow({ children: t.header.map((_, i) => mk(r[i] || { tokens: [] }, i, false, ri % 2 === 1)) })),
    ],
  });
}

// ---------- images ----------
function imageRuns(href, alt) {
  const p = path.resolve(CURRENT_MD_DIR, decodeURI(href));
  if (!/\.png$/i.test(p) || !fs.existsSync(p)) return [new TextRun({ text: '[image: ' + (alt || href) + ']', italics: true, color: GREY })];
  const buf = fs.readFileSync(p); const { w, h } = pngSize(buf);
  const scale = Math.min(1, (w < h ? 260 : 600) / w, 700 / h);
  return [new ImageRun({ type: 'png', data: buf, transformation: { width: Math.round(w * scale), height: Math.round(h * scale) } })];
}
function pngSize(buf) { return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) }; }
function screenshotBlock() {
  const dir = path.join(ROOT, 'docs', 'screenshots');
  const out = [];
  for (const f of fs.readdirSync(dir).filter(f => f.endsWith('.png')).sort()) {
    const buf = fs.readFileSync(path.join(dir, f));
    const { w, h } = pngSize(buf);
    const maxW = f.startsWith('15') || f.startsWith('16') ? 260 : 600; // px at 96 dpi
    const scale = Math.min(1, maxW / w, 760 / h);
    out.push(new Paragraph({ alignment: AlignmentType.CENTER, keepNext: true, spacing: { before: 120 }, children: [new ImageRun({ type: 'png', data: buf, transformation: { width: Math.round(w * scale), height: Math.round(h * scale) } })] }));
    out.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 160 }, children: [new TextRun({ text: f.replace('.png', '').replace(/^\d+-/, '').replace(/-/g, ' '), italics: true, size: 17, color: GREY, font: FONT })] }));
  }
  return out;
}

// ---------- markdown tokens -> docx blocks ----------
function convert(md, label, fileName) {
  const blocks = [];
  const tokens = marked.lexer(md);
  let first = true;
  for (const t of tokens) {
    if (t.type === 'heading') {
      if (t.depth === 1) {
        if (!first) { blocks.push(new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun({ text: decode(t.text) })] })); continue; }
        blocks.push(new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: `${label}. ${decode(t.text)}` })] }));
        blocks.push(new Paragraph({ children: [new TextRun({ text: `Source file: Product Details/${fileName}`, size: 16, color: GREY, font: FONT })], spacing: { after: 120 } }));
        first = false; continue;
      }
      const level = t.depth === 2 ? HeadingLevel.HEADING_2 : t.depth === 3 ? HeadingLevel.HEADING_3 : HeadingLevel.HEADING_4;
      blocks.push(new Paragraph({ heading: level, children: inlineRuns(t.tokens) }));
    } else if (t.type === 'paragraph') {
      blocks.push(new Paragraph({ children: inlineRuns(t.tokens, { size: 20 }), spacing: { after: 100 } }));
    } else if (t.type === 'list') {
      const ref = t.ordered ? 'num' : 'bullet';
      const walk = (list, lvl) => list.items.forEach(it => {
        const inl = it.tokens.filter(x => x.type === 'text' || x.type === 'paragraph');
        const runs = inl.flatMap(x => inlineRuns(x.tokens || [x], { size: 20 }));
        blocks.push(new Paragraph({ numbering: { reference: ref, level: lvl, instance: list.ordered ? blocks.length : undefined }, children: runs, spacing: { after: 40 } }));
        it.tokens.filter(x => x.type === 'list').forEach(sub => walk(sub, Math.min(lvl + 1, 2)));
      });
      walk(t, 0);
    } else if (t.type === 'table') {
      blocks.push(buildTable(t));
      blocks.push(new Paragraph({ children: [], spacing: { after: 80 } }));
    } else if (t.type === 'code') {
      if (t.lang === 'mermaid') blocks.push(...mermaidToParas(t.text));
      else t.text.split('\n').forEach(line => blocks.push(new Paragraph({ shading: { type: ShadingType.CLEAR, fill: 'F1F5F9', color: 'auto' }, spacing: { after: 0 }, children: [new TextRun({ text: line || ' ', font: MONO, size: 15 })] })));
      blocks.push(new Paragraph({ children: [], spacing: { after: 80 } }));
    } else if (t.type === 'hr') {
      blocks.push(new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: 'D0D7E2', space: 1 } }, spacing: { after: 120 } }));
    } else if (t.type === 'blockquote') {
      blocks.push(new Paragraph({ indent: { left: 400 }, children: inlineRuns(t.tokens.flatMap(x => x.tokens || []), { italics: true, size: 20 }) }));
    }
  }
  // place screenshots after the screenshots table (next table after the marker)
  const i = blocks.indexOf('__SCREENSHOTS__');
  if (i >= 0) {
    blocks.splice(i, 1);
    let j = i; while (j < blocks.length && !(blocks[j] instanceof Table)) j++;
    blocks.splice(j + 2, 0, ...screenshotBlock());
  }
  return blocks;
}

// ---------- assemble ----------
const children = [];
// title page
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 1200 }, children: fs.existsSync(path.join(ROOT, 'public/logo/SSA LOGO.png')) ? [new ImageRun({ type: 'png', data: fs.readFileSync(path.join(ROOT, 'public/logo/SSA LOGO.png')), transformation: (() => { const b = fs.readFileSync(path.join(ROOT, 'public/logo/SSA LOGO.png')); const { w, h } = pngSize(b); const s = 140 / h; return { width: Math.round(w * s), height: 140 }; })() })] : [] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 400, after: 120 }, children: [new TextRun({ text: 'School Inspection Management System', bold: true, size: 48, color: BLUE, font: FONT })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 120 }, children: [new TextRun({ text: 'Product Details', size: 30, color: GREEN, font: FONT })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 600 }, children: [new TextRun({ text: 'Samagra Shiksha, Assam · Phase 1 with New Modules (Modification V1–V7)', size: 22, color: GREY, font: FONT })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Technical documentation · Project documents · Gaps and roadmap · Requirements index', size: 20, font: FONT })] }));
children.push(new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 200 }, children: [new TextRun({ text: 'Status labels: Implemented · Partial · Not implemented', size: 20, italics: true, color: ORANGE, font: FONT })] }));
children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(new Paragraph({ children: [new TextRun({ text: 'Contents', bold: true, size: 32, color: BLUE, font: FONT })], spacing: { after: 200 } }));
children.push(new TableOfContents('Contents', { hyperlink: true, headingStyleRange: '1-2' }));
children.push(new Paragraph({ children: [new TextRun({ text: 'Right-click the table of contents and choose "Update Field" if page numbers are missing.', italics: true, size: 16, color: GREY, font: FONT })] }));
children.push(new Paragraph({ children: [new PageBreak()] }));

for (const part of PARTS) {
  children.push(new Paragraph({ pageBreakBefore: true, alignment: AlignmentType.CENTER, spacing: { before: 3000, after: 200 }, children: [new TextRun({ text: part.title, bold: true, size: 44, color: BLUE, font: FONT })] }));
  children.push(new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: part.intro, size: 22, color: GREY, font: FONT })] }));
  part.files.forEach((f, i) => children.push(...convert(fs.readFileSync(path.join(DOCDIR, f), 'utf8'), part.key + (i + 1), f)));
}
const REQ = path.join(DOCDIR, 'Requirements');
const desc = f => /PRD/.test(f) ? 'Product Requirements Document v3.0 (cited in code as "PRD x.y")'
  : /New Module/.test(f) ? 'Request for the New Modules: Map View, Scheduling, Rating'
  : /ApplicationV(\d)/.test(f) ? 'Modification request V' + f.match(/V(\d)/)[1]
  : /PRODUCT REQUIREMENTS/.test(f) ? 'Earlier product requirements document'
  : /^Documentation/.test(f) ? 'Brief for the technical documentation (Part A)'
  : /^Document\./.test(f) ? 'Earlier requirements note'
  : /\.png$/i.test(f) ? 'Paper inspection form: ' + f.replace(/\.png$/i, '') : '';
const reqFiles = fs.existsSync(REQ) ? fs.readdirSync(REQ).filter(f => !f.startsWith('~$')).sort() : [];
const cell = x => ({ text: x, tokens: [{ type: 'text', text: x }] });
children.push(new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: 'Part C: Requirements (appendix)' })] }));
children.push(new Paragraph({ spacing: { after: 120 }, children: [new TextRun({ text: 'The source requirement documents are kept as separate files in Product Details/Requirements/ (copies of Documents/, which remains the master copy). They are listed here; open each file for its full text and images.', size: 20, font: FONT })] }));
children.push(buildTable({ header: ['Sl', 'File', 'Description', 'Size'].map(cell),
  rows: reqFiles.map((f, i) => [String(i + 1), f, desc(f), Math.round(fs.statSync(path.join(REQ, f)).size / 1024) + ' KB'].map(cell)) }));

const heading = (size, color, before, after) => ({ run: { font: FONT, size, bold: true, color }, paragraph: { spacing: { before, after }, keepNext: true, keepLines: true } });
const doc = new Document({
  creator: 'School Inspection project', title: 'School Inspection Management System: Product Details',
  styles: {
    default: { document: { run: { font: FONT, size: 20 } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, ...heading(34, BLUE, 240, 120), paragraph: { spacing: { before: 240, after: 120 }, keepNext: true, keepLines: true, outlineLevel: 0, border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: ORANGE, space: 4 } } } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, ...heading(27, BLUE, 280, 100), paragraph: { spacing: { before: 280, after: 100 }, keepNext: true, keepLines: true, outlineLevel: 1 } },
      { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true, ...heading(23, GREEN, 200, 80), paragraph: { spacing: { before: 200, after: 80 }, keepNext: true, keepLines: true, outlineLevel: 2 } },
      { id: 'Heading4', name: 'Heading 4', basedOn: 'Normal', next: 'Normal', quickFormat: true, ...heading(21, '1E293B', 160, 60), paragraph: { spacing: { before: 160, after: 60 }, keepNext: true, keepLines: true, outlineLevel: 3 } },
    ],
  },
  numbering: {
    config: [
      { reference: 'bullet', levels: [0, 1, 2].map(l => ({ level: l, format: LevelFormat.BULLET, text: ['•', '◦', '▪'][l], alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 360 + l * 360, hanging: 260 } } } })) },
      { reference: 'num', levels: [0, 1, 2].map(l => ({ level: l, format: l === 1 ? LevelFormat.LOWER_LETTER : LevelFormat.DECIMAL, text: `%${l + 1}.`, alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 360 + l * 360, hanging: 300 } } } })) },
      { reference: 'diag', levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 420, hanging: 300 } } } }] },
    ],
  },
  features: { updateFields: true },
  sections: [{
    properties: { page: { size: { width: PAGE_W, height: 16838 }, margin: { top: 1000, bottom: 1000, left: MARGIN, right: MARGIN } } },
    headers: { default: new Header({ children: [new Paragraph({ alignment: AlignmentType.RIGHT, children: [new TextRun({ text: 'School Inspection Management System · Product Details', size: 16, color: GREY, font: FONT })] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: ['Page ', PageNumber.CURRENT, ' of ', PageNumber.TOTAL_PAGES], size: 16, color: GREY, font: FONT })] })] }) },
    children,
  }],
});
Packer.toBuffer(doc).then(b => { fs.writeFileSync(OUT, b); console.log('written', OUT, b.length, 'bytes'); });
