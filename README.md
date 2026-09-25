# Cezareja — redizajn

| Mapa | Što je unutra |
|---|---|
| `00-nacrti` | Nacrti stranica (AI koncept, izmišljeni podaci — vidi 00-brand/podaci-tvrtke.md) |
| `00-brand` | Stari logo, favicon, **podaci-tvrtke.md** (pravi kontakti, poslovnice, OIB, certifikati) |
| `01-naslovna` … `08-kontakt` | Po stranica: `sadrzaj.md` (tekst sa starog sitea + što nedostaje) i `slike/` (web verzije, 2400 px) |
| `web` | **Redizajn — statički site.** Otvoriti `web/index.html`. Uređuje se `web/src/` (zaglavlje, podnožje, stranice), zatim `web/build.ps1` sastavi `*.html` |
| `_izvorno` | Originali: dron fotke (8 MB), 4K video, sve preuzeto sa starog sitea |

Slike u `slike/` imaju broj dron snimke u imenu (npr. `0080-…` = `DJI_…_0080_D.jpg`), pa se original uvijek nađe u `_izvorno/dron`.
Svih 14 dron fotki (web) je u `06-lokacije/slike/dron`; ostale stranice imaju izbor.

⚠️ Za dron fotke ne znamo koja je koja lokacija — klijent treba označiti (vidi 06-lokacije/sadrzaj.md).
