<?php
/**
 * Kontakt obrazac — Cezareja d.o.o.
 *
 * Prima POST s kontakt.html, provjerava podatke i salje e-mail preko SMTP-a
 * (postavke i lozinka u mail-config.php). Odgovara JSON-om (kad salje
 * JavaScript) ili preusmjerava natrag na kontakt.html (bez JavaScripta).
 *
 * SPF domene cezareja.hr vec dopusta VPS (185.213.27.139), pa poruke s
 * web@cezareja.hr prolaze Googleov filtar.
 */

// ------------------------------------------------------------- POSTAVKE

$config = require __DIR__ . '/mail-config.php';

// Kamo ide koja vrsta upita. Prazno = adresa 'to' iz mail-config.php.
const TO_BY_TYPE = [
    'Otkup'          => '',
    'Repromaterijal' => '',
    'Ostalo'         => '',
];

const FROM_NAME = 'Cezareja web';

// Najmanje sekundi od otvaranja stranice do slanja — robot salje odmah.
const MIN_SECONDS = 3;

// Najvise poruka s iste IP adrese u jednom satu.
const MAX_PER_HOUR = 5;

// true = ne salje e-mail nego zapisuje poruku u mail-test.log (za probu).
const TEST_MODE = false;

// ----------------------------------------------------------------------

date_default_timezone_set('Europe/Zagreb');
header('X-Content-Type-Options: nosniff');

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond(bool $ok, string $message, int $status = 200): void
{
    global $wantsJson;

    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: kontakt.html?poslano=' . ($ok ? '1' : '0') . '#upit', true, 303);
    }
    exit;
}

function field(string $name, int $max): string
{
    $value = isset($_POST[$name]) ? trim((string) $_POST[$name]) : '';
    // Bez kontrolnih znakova; poruka smije imati novi red.
    $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';

    return mb_substr($value, 0, $max);
}

function encodeName(string $name): string
{
    return '=?UTF-8?B?' . base64_encode($name) . '?=';
}

/**
 * Minimalni SMTP klijent (SSL na 465 ili STARTTLS na 587, AUTH LOGIN).
 *
 * @return string|null null = poslano, inace opis greske
 */
function smtpSend(array $c, string $to, string $message): ?string
{
    $ssl = (int) $c['port'] === 465;
    $verify = $c['verify_ssl'] ?? true;
    $ctx = stream_context_create(['ssl' => [
        'peer_name' => $c['host'],
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
    ]]);
    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return "spajanje: {$errstr} ({$errno})";
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (?string $line, int $expect) use ($fp, $read): ?string {
        if ($line !== null) {
            fwrite($fp, $line . "\r\n");
        }
        $reply = $read();
        return (int) substr($reply, 0, 3) === $expect ? null : trim($reply);
    };

    $steps = [[null, 220], ['EHLO cezareja.hr', 250]];
    foreach ($steps as [$line, $code]) {
        if ($err = $cmd($line, $code)) {
            fclose($fp);
            return $err;
        }
    }

    if (!$ssl) {
        if ($err = $cmd('STARTTLS', 220)) {
            fclose($fp);
            return $err;
        }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($err = $cmd('EHLO cezareja.hr', 250)) {
            fclose($fp);
            return $err;
        }
    }

    // Tocka na pocetku retka udvostrucuje se (RFC 5321); base64 tijelo je nema, zaglavlja mogu.
    $data = preg_replace('/^\./m', '..', $message);

    $steps = [
        ['AUTH LOGIN', 334],
        [base64_encode($c['username']), 334],
        [base64_encode($c['password']), 235],
        ['MAIL FROM:<' . $c['from'] . '>', 250],
        ['RCPT TO:<' . $to . '>', 250],
        ['DATA', 354],
        [$data . "\r\n.", 250],
    ];
    foreach ($steps as [$line, $code]) {
        if ($err = $cmd($line, $code)) {
            fclose($fp);
            return $err;
        }
    }

    $cmd('QUIT', 221);
    fclose($fp);

    return null;
}

function oneLine(string $value): string
{
    // Zastita od ubacivanja zaglavlja: u zaglavlja ide samo jedan redak.
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Neispravan zahtjev.', 405);
}

// Zamka za robote: polje koje covjek ne vidi mora ostati prazno.
if (field('web', 200) !== '') {
    respond(true, 'Hvala! Vaš upit je poslan.');
}

$started = (int) field('t', 20);
if ($started > 0 && (time() - intdiv($started, 1000)) < MIN_SECONDS) {
    respond(false, 'Poruka je poslana prebrzo. Pokušajte ponovno.', 429);
}

$ime     = oneLine(field('ime', 120));
$tvrtka  = oneLine(field('tvrtka', 160));
$email   = oneLine(field('email', 200));
$telefon = oneLine(field('telefon', 50));
$vrsta   = oneLine(field('vrsta', 40));
$poruka  = field('poruka', 5000);
$privola = isset($_POST['privola']);

$errors = [];
if ($ime === '') {
    $errors[] = 'upišite ime i prezime';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'upišite ispravnu e-mail adresu';
}
if (!array_key_exists($vrsta, TO_BY_TYPE)) {
    $vrsta = 'Ostalo';
}
if (mb_strlen($poruka) < 5) {
    $errors[] = 'upišite poruku';
}
if (!$privola) {
    $errors[] = 'potvrdite privolu za obradu podataka';
}
if ($errors) {
    respond(false, 'Molimo ' . implode(', ', $errors) . '.', 422);
}

// Ogranicenje po IP adresi (datoteka u privremenoj mapi posluzitelja).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'nepoznato';
$rateFile = sys_get_temp_dir() . '/cezareja-form-' . md5($ip);
$hits = [];
if (is_file($rateFile)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string) file_get_contents($rateFile))),
        fn ($t) => $t > time() - 3600
    );
}
if (count($hits) >= MAX_PER_HOUR) {
    respond(false, 'Poslali ste previše poruka. Pokušajte ponovno za sat vremena ili nas nazovite.', 429);
}
$hits[] = time();
@file_put_contents($rateFile, implode(',', $hits), LOCK_EX);

// Poruka
$to = TO_BY_TYPE[$vrsta] ?: $config['to'];
$subject = 'Upit s weba: ' . $vrsta . ' — ' . $ime;

$body = "Novi upit s obrasca na cezareja.hr\n"
    . str_repeat('-', 40) . "\n"
    . "Vrsta upita:   {$vrsta}\n"
    . "Ime i prezime: {$ime}\n"
    . 'Tvrtka / OPG:  ' . ($tvrtka !== '' ? $tvrtka : '-') . "\n"
    . "E-mail:        {$email}\n"
    . 'Telefon:       ' . ($telefon !== '' ? $telefon : '-') . "\n"
    . str_repeat('-', 40) . "\n\n"
    . $poruka . "\n\n"
    . str_repeat('-', 40) . "\n"
    . 'Poslano: ' . date('d.m.Y. H:i') . ", IP: {$ip}\n"
    . "Korisnik je potvrdio privolu za obradu podataka radi odgovora na upit.\n";

$message = implode("\r\n", [
    'Date: ' . date('r'),
    'From: ' . encodeName(FROM_NAME) . ' <' . $config['from'] . '>',
    'To: <' . $to . '>',
    'Reply-To: ' . encodeName($ime) . ' <' . $email . '>',
    'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@cezareja.hr>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
    'X-Mailer: Cezareja web',
    '',
    rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
]);

if (TEST_MODE) {
    $sent = (bool) file_put_contents(__DIR__ . '/mail-test.log', "TO: {$to}\n{$subject}\n{$body}\n\n", FILE_APPEND | LOCK_EX);
} else {
    $error = smtpSend($config, $to, $message);
    $sent = $error === null;
    if (!$sent) {
        error_log('Cezareja obrazac, SMTP: ' . $error);
    }
}

if (!$sent) {
    respond(false, 'Poruku trenutno nije moguće poslati. Nazovite nas na +385 32 550 399 ili pišite na cezareja@cezareja.hr.', 500);
}

respond(true, 'Hvala! Vaš upit je poslan, javit ćemo vam se u najkraćem roku.');
