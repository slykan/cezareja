# Sastavlja stranice: src/_header.html + src/pages/<ime>.html + src/_footer.html -> <ime>.html
# Prvi redak svake stranice u src/pages je zaglavlje:
#   <!-- naslov | opis | aktivna-stavka | klasa-tijela -->
# Pokretanje:  powershell -ExecutionPolicy Bypass -File build.ps1

$root = $PSScriptRoot
$utf8 = New-Object System.Text.UTF8Encoding $false
$header = [IO.File]::ReadAllText("$root\src\_header.html", $utf8)
$footer = [IO.File]::ReadAllText("$root\src\_footer.html", $utf8)
$items = 'index', 'o-nama', 'djelatnosti', 'otkup', 'repromaterijal', 'poslovnice', 'kontakt'

foreach ($page in Get-ChildItem "$root\src\pages" -Filter *.html) {
    $body = [IO.File]::ReadAllText($page.FullName, $utf8)
    if ($body -notmatch '^\s*<!--(.*?)-->\r?\n') { throw "Nedostaje zaglavlje u $($page.Name)" }
    $meta = $Matches[1].Split('|') | ForEach-Object { $_.Trim() }
    $body = $body.Substring($Matches[0].Length)

    $h = $header.Replace('{{TITLE}}', $meta[0]).Replace('{{DESC}}', $meta[1]).Replace('{{BODYCLASS}}', $meta[3])
    foreach ($i in $items) {
        $h = $h.Replace("{{CUR:$i}}", $(if ($i -eq $meta[2]) { ' aria-current="page"' } else { '' }))
    }

    [IO.File]::WriteAllText("$root\$($page.Name)", $h + $body + $footer, $utf8)
    "  $($page.Name)"
}
