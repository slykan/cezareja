# Sastavlja stranice na oba jezika:
#   src/pages/<ime>.html     + src/_header.html    + src/_footer.html    -> <ime>.html
#   src/pages/en/<ime>.html  + src/_header.en.html + src/_footer.en.html -> en/<ime>.html
# Ikone (src/_icons.svg) umecu se u oba zaglavlja.
#
# Prvi redak svake stranice u src/pages je zaglavlje:
#   <!-- naslov | opis | aktivna-stavka | klasa-tijela | ista-stranica-na-drugom-jeziku -->
# Pokretanje:  & .\build.ps1

$root = $PSScriptRoot
$utf8 = New-Object System.Text.UTF8Encoding $false
$icons = [IO.File]::ReadAllText("$root\src\_icons.svg", $utf8)

$langs = @(
    @{ src = "$root\src\pages";    out = $root;      header = '_header.html';    footer = '_footer.html';
       items = 'index', 'o-nama', 'djelatnosti', 'otkup', 'repromaterijal', 'poslovnice', 'kontakt' },
    @{ src = "$root\src\pages\en"; out = "$root\en"; header = '_header.en.html'; footer = '_footer.en.html';
       items = 'index', 'about', 'activities', 'grain-purchase', 'farm-inputs', 'branches', 'contact' }
)

foreach ($l in $langs) {
    $header = [IO.File]::ReadAllText("$root\src\$($l.header)", $utf8).Replace('{{ICONS}}', $icons)
    $footer = [IO.File]::ReadAllText("$root\src\$($l.footer)", $utf8)
    New-Item -ItemType Directory -Force $l.out | Out-Null

    foreach ($page in Get-ChildItem $l.src -Filter *.html -File) {
        $body = [IO.File]::ReadAllText($page.FullName, $utf8)
        if ($body -notmatch '^\s*<!--(.*?)-->\r?\n') { throw "Nedostaje zaglavlje u $($page.FullName)" }
        $meta = $Matches[1].Split('|') | ForEach-Object { $_.Trim() }
        if ($meta.Count -lt 5) { throw "Zaglavlje u $($page.Name) treba 5 polja (nedostaje stranica na drugom jeziku)" }
        $body = $body.Substring($Matches[0].Length)

        $h = $header.Replace('{{TITLE}}', $meta[0]).Replace('{{DESC}}', $meta[1]).Replace('{{BODYCLASS}}', $meta[3]).
            Replace('{{SELF}}', $page.Name).Replace('{{ALT}}', $meta[4])
        foreach ($i in $l.items) {
            $h = $h.Replace("{{CUR:$i}}", $(if ($i -eq $meta[2]) { ' aria-current="page"' } else { '' }))
        }

        [IO.File]::WriteAllText("$($l.out)\$($page.Name)", $h + $body + $footer, $utf8)
        "  " + $(if ($l.out -ne $root) { 'en/' } else { '' }) + $page.Name
    }
}
