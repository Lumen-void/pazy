#!/usr/bin/env python3
from pathlib import Path
import html
import re
import subprocess
import sys

base = Path('/Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/docs')
md_path = base / 'Pazy_Architecture_Working_Guide.md'
html_path = base / 'Pazy_Architecture_Working_Guide.html'
pdf_path = base / 'Pazy_Architecture_Working_Guide.pdf'

text = md_path.read_text(encoding='utf-8')
lines = text.splitlines()
out = []
in_code = False
code_lines = []
in_table = False
table_lines = []
in_ul = False

def flush_ul():
    global in_ul
    if in_ul:
        out.append('</ul>')
        in_ul = False

def render_inline(s):
    s = html.escape(s)
    s = re.sub(r'`([^`]+)`', r'<code>\1</code>', s)
    s = re.sub(r'!\[([^\]]*)\]\(([^)]+)\)', lambda m: f'<figure><img src="{html.escape(m.group(2))}" alt="{html.escape(m.group(1))}"><figcaption>{html.escape(m.group(1))}</figcaption></figure>', s)
    return s

def flush_table():
    global in_table, table_lines
    if not in_table:
        return
    rows = []
    for row in table_lines:
        if re.match(r'^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$', row):
            continue
        cells = [c.strip() for c in row.strip().strip('|').split('|')]
        rows.append(cells)
    if rows:
        out.append('<table>')
        out.append('<thead><tr>' + ''.join(f'<th>{render_inline(c)}</th>' for c in rows[0]) + '</tr></thead>')
        if len(rows) > 1:
            out.append('<tbody>')
            for r in rows[1:]:
                out.append('<tr>' + ''.join(f'<td>{render_inline(c)}</td>' for c in r) + '</tr>')
            out.append('</tbody>')
        out.append('</table>')
    table_lines = []
    in_table = False

for line in lines:
    if line.startswith('```'):
        flush_table(); flush_ul()
        if not in_code:
            in_code = True
            code_lines = []
        else:
            out.append('<pre><code>' + html.escape('\n'.join(code_lines)) + '</code></pre>')
            in_code = False
        continue
    if in_code:
        code_lines.append(line)
        continue
    if line.strip().startswith('|') and line.strip().endswith('|'):
        flush_ul()
        in_table = True
        table_lines.append(line)
        continue
    if in_table:
        flush_table()
    if not line.strip():
        flush_ul()
        continue
    if line.startswith('# '):
        flush_ul(); out.append(f'<h1>{render_inline(line[2:].strip())}</h1>')
    elif line.startswith('## '):
        flush_ul(); out.append(f'<h2>{render_inline(line[3:].strip())}</h2>')
    elif line.startswith('### '):
        flush_ul(); out.append(f'<h3>{render_inline(line[4:].strip())}</h3>')
    elif line.startswith('- '):
        if not in_ul:
            out.append('<ul>'); in_ul = True
        out.append(f'<li>{render_inline(line[2:].strip())}</li>')
    else:
        flush_ul(); out.append(f'<p>{render_inline(line.strip())}</p>')
flush_table(); flush_ul()

body = '\n'.join(out)
css = '''
@page { size: A4 landscape; margin: 12mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #123042; background: #f5fbff; font-size: 12px; line-height: 1.45; }
body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(circle at 12% 8%, rgba(52, 211, 153, .22), transparent 28%), radial-gradient(circle at 86% 10%, rgba(59,130,246,.18), transparent 26%), linear-gradient(135deg, #f9fffb, #eaf7ff 48%, #f3fbf6); z-index: -1; }
.cover { min-height: 92vh; display: grid; align-content: center; padding: 34px; border: 1px solid #cce2ef; border-radius: 28px; background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(229,246,255,.82)); box-shadow: 0 18px 60px rgba(19, 64, 91, .12); page-break-after: always; }
.cover h1 { font-size: 44px; margin: 0 0 16px; color: #062c2e; max-width: 900px; }
.cover p { font-size: 18px; max-width: 900px; color: #4c6575; }
.cover .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 30px; }
.cover .meta div { padding: 16px; border-radius: 18px; background: #fff; border: 1px solid #cce2ef; font-weight: 700; color: #19445f; }
h1, h2, h3 { font-family: Georgia, 'Times New Roman', serif; color: #082c3d; page-break-after: avoid; }
h1 { font-size: 30px; margin: 22px 0 14px; }
h2 { font-size: 24px; margin: 24px 0 12px; padding: 12px 16px; border-radius: 18px; background: linear-gradient(135deg, #ffffff, #e7f6fb); border: 1px solid #c7dfeb; }
h3 { font-size: 18px; margin: 18px 0 10px; }
p { margin: 8px 0 12px; color: #425a6b; }
code { background: #eef8fb; color: #153d55; padding: 2px 5px; border-radius: 6px; font-family: Menlo, Monaco, Consolas, monospace; }
pre { white-space: pre-wrap; break-inside: avoid; page-break-inside: avoid; background: linear-gradient(135deg, #0e3040, #174b55); color: #eafff5; padding: 16px; border-radius: 18px; border: 1px solid #245e6b; font-family: Menlo, Monaco, Consolas, monospace; font-size: 10px; line-height: 1.34; box-shadow: inset 0 0 0 1px rgba(255,255,255,.05); }
table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 10px 0 16px; background: rgba(255,255,255,.92); border: 1px solid #c7dfeb; border-radius: 16px; overflow: hidden; page-break-inside: avoid; }
th, td { padding: 9px 11px; border-bottom: 1px solid #dcecf4; vertical-align: top; }
th { text-align: left; background: #dff4ee; color: #0f513c; font-weight: 800; }
tr:last-child td { border-bottom: 0; }
ul { margin: 8px 0 14px 20px; color: #425a6b; }
figure { margin: 12px 0 24px; padding: 12px; border: 1px solid #c7dfeb; border-radius: 20px; background: rgba(255,255,255,.96); box-shadow: 0 10px 30px rgba(15, 57, 84, .08); page-break-inside: avoid; }
figure img { width: 100%; height: 520px; object-fit: cover; object-position: top; border-radius: 14px; border: 1px solid #d7e9f0; display: block; background: #fff; }
figcaption { margin-top: 8px; color: #567184; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
.section { page-break-inside: avoid; }
.footer { margin-top: 28px; padding: 16px; border-radius: 18px; background: #0e3040; color: #e7fff7; }
@media print { h2 { break-before: auto; } figure { break-inside: avoid; } }
'''
html_doc = f'''<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Pazy Architecture Working Guide</title>
<style>{css}</style>
</head>
<body>
<section class="cover">
  <h1>Pazy Plain Architecture and Working Guide</h1>
  <p>End-to-end mapping of the pure PHP, CSS, JavaScript, and MySQL finance automation tool, including live screenshots, module splits, folder tree, database tree, request routing, workflows, integrations, and verification commands.</p>
  <div class="meta">
    <div>Stack<br>Pure PHP + MySQL</div>
    <div>Server<br>XAMPP Apache</div>
    <div>App URL<br>localhost/pazy/purephp/public</div>
    <div>Generated<br>2026-05-19</div>
  </div>
</section>
{body}
<div class="footer">Generated from the local running Pazy Plain app and the repository under /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp.</div>
</body>
</html>'''
html_path.write_text(html_doc, encoding='utf-8')
chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
cmd = [chrome, '--headless=new', '--disable-gpu', '--no-sandbox', f'--print-to-pdf={pdf_path}', str(html_path)]
subprocess.run(cmd, check=True)
print(pdf_path)
