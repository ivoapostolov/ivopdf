"""Rasterize every page of a PDF to PNG (next to the PDF) and print text + bookmarks for a quick review.

Usage: python preview.py output.pdf [zoom]
Requires PyMuPDF: pip install pymupdf
"""
import os
import sys

try:
    import fitz  # PyMuPDF
except ImportError:
    sys.exit("PyMuPDF is not installed: pip install pymupdf (or open the PDF in a browser instead)")

if len(sys.argv) < 2:
    sys.exit("Usage: python preview.py output.pdf [zoom]")

path = sys.argv[1]
zoom = float(sys.argv[2]) if len(sys.argv) > 2 else 1.5
base = os.path.splitext(path)[0]
doc = fitz.open(path)
sys.stdout.reconfigure(encoding="utf-8")

print(f"{path}: {len(doc)} page(s), metadata title={doc.metadata.get('title')!r}")
for level, title, page in doc.get_toc():
    print(f"  bookmark {'  ' * (level - 1)}{title} -> page {page}")

for i, page in enumerate(doc, start=1):
    png = f"{base}-page{i}.png"
    page.get_pixmap(matrix=fitz.Matrix(zoom, zoom)).save(png)
    text = page.get_text().strip().replace("\n", " | ")
    print(f"\n--- page {i}: {png}")
    print(text[:600] + ("..." if len(text) > 600 else ""))
