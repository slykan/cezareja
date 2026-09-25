<?php
/**
 * Kontakt obrazac — Cezareja d.o.o.
 *
 * Prima POST s kontakt.html, provjerava podatke i salje e-mail preko SMTP-a
 * (postavke i lozinka u mail-config.php). Odgovara JSON-om (kad salje
 * JavaScript) ili preusmjerava natrag na kontakt.html (bez JavaScripta).
 *
 * Radi na PHP 5.6 i novijem — posluzitelj starog sitea moze imati stari PHP.
 *
 * SPF domene cezareja.hr vec dopusta VPS (185.213.27.139), pa poruke s
 * web@cezareja.hr prolaze Googleov filtar.
 */

// ------------------------------------------------------------- POSTAVKE

// Kamo ide koja vrsta upita. Prazno = adresa 'to' iz mail-config.php.
$TO_BY_TYPE = array(
    'Otkup'          => '',
    'Repromaterijal' => '',
    'Ostalo'         => '',
);

define('FROM_NAME', 'Cezareja web');

// Najmanje sekundi od otvaranja stranice do slanja — robot salje odmah.
define('MIN_SECONDS', 3);

// Najvise poruka s iste IP adrese u jednom satu.
define('MAX_PER_HOUR', 5);

// true = ne salje e-mail nego zapisuje poruku u mail-test.log (za probu).
define('TEST_MODE', false);

// ----------------------------------------------------------------------

date_default_timezone_set('Europe/Zagreb');
header('X-Content-Type-Options: nosniff');

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
$lang = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'hr';

// Poruke posjetitelju na jeziku stranice s koje je poslao obrazac.
$MESSAGES = array(
    'hr' => array(
        'bad_request' => 'Neispravan zahtjev.',
        'unavailable' => 'Obrazac trenutno nije dostupan. Nazovite nas na +385 32 550 399 ili pišite na cezareja@cezareja.hr.',
        'sent'        => 'Hvala! Vaš upit je poslan, javit ćemo vam se u najkraćem roku.',
        'sent_short'  => 'Hvala! Vaš upit je poslan.',
        'too_fast'    => 'Poruka je poslana prebrzo. Pokušajte ponovno.',
        'too_many'    => 'Poslali ste previše poruka. Pokušajte ponovno za sat vremena ili nas nazovite.',
        'failed'      => 'Poruku trenutno nije moguće poslati. Nazovite nas na +385 32 550 399 ili pišite na cezareja@cezareja.hr.',
        'please'      => 'Molimo',
        'err_name'    => 'upišite ime i prezime',
        'err_email'   => 'upišite ispravnu e-mail adresu',
        'err_message' => 'upišite poruku',
        'err_consent' => 'potvrdite privolu za obradu podataka',
    ),
    'en' => array(
        'bad_request' => 'Invalid request.',
        'unavailable' => 'The form is currently unavailable. Please call us on +385 32 550 399 or email cezareja@cezareja.hr.',
        'sent'        => 'Thank you! Your enquiry has been sent and we will get back to you shortly.',
        'sent_short'  => 'Thank you! Your enquiry has been sent.',
        'too_fast'    => 'The form was sent too quickly. Please try again.',
        'too_many'    => 'You have sent too many messages. Please try again in an hour or give us a call.',
        'failed'      => 'Your message cannot be sent right now. Please call us on +385 32 550 399 or email cezareja@cezareja.hr.',
        'please'      => 'Please',
        'err_name'    => 'enter your full name',
        'err_email'   => 'enter a valid email address',
        'err_message' => 'enter a message',
        'err_consent' => 'confirm your consent to the processing of your data',
    ),
);

function t($key)
{
    global $MESSAGES, $lang;

    return $MESSAGES[$lang][$key];
}

function respond($ok, $message, $status = 200)
{
    global $wantsJson, $lang;

    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => (bool) $ok, 'message' => $message), JSON_UNESCAPED_UNICODE);
    } else {
        $page = $lang === 'en' ? 'en/contact.html' : 'kontakt.html';
        header('Location: ' . $page . '?poslano=' . ($ok ? '1' : '0') . '#upit', true, 303);
    }
    exit;
}

function cut($value, $max)
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function textLength($value)
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function field($name, $max)
{
    $value = isset($_POST[$name]) ? trim((string) $_POST[$name]) : '';
    // Bez kontrolnih znakova; poruka smije imati novi red.
    $clean = preg_replace('/[^\P{C}\n\t]/u', '', $value);

    return cut($clean === null ? '' : $clean, $max);
}

function oneLine($value)
{
    // Zastita od ubacivanja zaglavlja: u zaglavlja ide samo jedan redak.
    return trim(str_replace(array("\r", "\n"), ' ', $value));
}

function encodeName($name)
{
    return '=?UTF-8?B?' . base64_encode($name) . '?=';
}

/**
 * Jedan redak odgovora SMTP posluzitelja (visered odgovori spojeni).
 */
function smtpRead($fp)
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    return $data;
}

/**
 * @return string|null null = ocekivani odgovor, inace odgovor posluzitelja
 */
function smtpCmd($fp, $line, $expect)
{
    if ($line !== null) {
        fwrite($fp, $line . "\r\n");
    }
    $reply = smtpRead($fp);

    return (int) substr($reply, 0, 3) === $expect ? null : trim($reply);
}

/**
 * Minimalni SMTP klijent (SSL na 465 ili STARTTLS na 587, AUTH LOGIN).
 *
 * @return string|null null = poslano, inace opis greske
 */
function smtpSend(array $c, $to, $message)
{
    $ssl = (int) $c['port'] === 465;
    $verify = isset($c['verify_ssl']) ? (bool) $c['verify_ssl'] : true;
    $ctx = stream_context_create(array('ssl' => array(
        'peer_name' => $c['host'],
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
    )));
    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'spajanje: ' . $errstr . ' (' . $errno . ')';
    }
    stream_set_timeout($fp, 15);

    $steps = array(array(null, 220), array('EHLO cezareja.hr', 250));
    if (!$ssl) {
        $steps[] = array('STARTTLS', 220);
    }
    foreach ($steps as $step) {
        $err = smtpCmd($fp, $step[0], $step[1]);
        if ($err !== null) {
            fclose($fp);
            return $err;
        }
    }

    if (!$ssl) {
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $err = smtpCmd($fp, 'EHLO cezareja.hr', 250);
        if ($err !== null) {
            fclose($fp);
            return $err;
        }
    }

    // Tocka na pocetku retka udvostrucuje se (RFC 5321).
    $data = preg_replace('/^\./m', '..', $message);

    $steps = array(
        array('AUTH LOGIN', 334),
        array(base64_encode($c['username']), 334),
        array(base64_encode($c['password']), 235),
        array('MAIL FROM:<' . $c['from'] . '>', 250),
        array('RCPT TO:<' . $to . '>', 250),
        array('DATA', 354),
        array($data . "\r\n.", 250),
    );
    foreach ($steps as $i => $step) {
        $err = smtpCmd($fp, $step[0], $step[1]);
        if ($err !== null) {
            fclose($fp);
            return ($i <= 2 ? 'prijava: ' : '') . $err;
        }
    }

    smtpCmd($fp, 'QUIT', 221);
    fclose($fp);

    return null;
}

// ------------------------------------------------------------- OBRADA

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, t('bad_request'), 405);
}

$configFile = __DIR__ . '/mail-config.php';
$config = is_file($configFile) ? include $configFile : null;
if (!is_array($config) || empty($config['host']) || empty($config['to'])) {
    error_log('Cezareja obrazac: nedostaje ili je neispravan ' . $configFile);
    respond(false, t('unavailable'), 500);
}

// Zamka za robote: polje koje covjek ne vidi mora ostati prazno.
if (field('web', 200) !== '') {
    respond(true, t('sent_short'));
}

$started = (int) field('t', 20);
if ($started > 0 && (time() - (int) floor($started / 1000)) < MIN_SECONDS) {
    respond(false, t('too_fast'), 429);
}

$ime     = oneLine(field('ime', 120));
$tvrtka  = oneLine(field('tvrtka', 160));
$email   = oneLine(field('email', 200));
$telefon = oneLine(field('telefon', 50));
$vrsta   = oneLine(field('vrsta', 40));
$poruka  = field('poruka', 5000);
$privola = isset($_POST['privola']);

$errors = array();
if ($ime === '') {
    $errors[] = t('err_name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = t('err_email');
}
if (!array_key_exists($vrsta, $TO_BY_TYPE)) {
    $vrsta = 'Ostalo';
}
if (textLength($poruka) < 5) {
    $errors[] = t('err_message');
}
if (!$privola) {
    $errors[] = t('err_consent');
}
if ($errors) {
    respond(false, t('please') . ' ' . implode(', ', $errors) . '.', 422);
}

// Ogranicenje po IP adresi (datoteka u privremenoj mapi posluzitelja).
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'nepoznato';
if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']; // site je iza Cloudflarea
}
$rateFile = sys_get_temp_dir() . '/cezareja-form-' . md5($ip);
$hits = array();
if (is_file($rateFile)) {
    foreach (explode(',', (string) file_get_contents($rateFile)) as $t) {
        if ((int) $t > time() - 3600) {
            $hits[] = (int) $t;
        }
    }
}
if (count($hits) >= MAX_PER_HOUR) {
    respond(false, t('too_many'), 429);
}
$hits[] = time();
@file_put_contents($rateFile, implode(',', $hits), LOCK_EX);

// Poruka
$to = $TO_BY_TYPE[$vrsta] !== '' ? $TO_BY_TYPE[$vrsta] : $config['to'];
$subject = 'Upit s weba: ' . $vrsta . ' — ' . $ime;

$body = "Novi upit s obrasca na cezareja.hr\n"
    . str_repeat('-', 40) . "\n"
    . 'Vrsta upita:   ' . $vrsta . "\n"
    . 'Jezik stranice: ' . strtoupper($lang) . "\n"
    . 'Ime i prezime: ' . $ime . "\n"
    . 'Tvrtka / OPG:  ' . ($tvrtka !== '' ? $tvrtka : '-') . "\n"
    . 'E-mail:        ' . $email . "\n"
    . 'Telefon:       ' . ($telefon !== '' ? $telefon : '-') . "\n"
    . str_repeat('-', 40) . "\n\n"
    . $poruka . "\n\n"
    . str_repeat('-', 40) . "\n"
    . 'Poslano: ' . date('d.m.Y. H:i') . ', IP: ' . $ip . "\n"
    . "Korisnik je potvrdio privolu za obradu podataka radi odgovora na upit.\n";

$message = implode("\r\n", array(
    'Date: ' . date('r'),
    'From: ' . encodeName(FROM_NAME) . ' <' . $config['from'] . '>',
    'To: <' . $to . '>',
    'Reply-To: ' . encodeName($ime) . ' <' . $email . '>',
    'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    'Message-ID: <' . md5(uniqid(mt_rand(), true)) . '@cezareja.hr>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
    'X-Mailer: Cezareja web',
    '',
    rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
));

if (TEST_MODE) {
    $sent = (bool) file_put_contents(__DIR__ . '/mail-test.log', 'TO: ' . $to . "\n" . $subject . "\n" . $body . "\n\n", FILE_APPEND | LOCK_EX);
} else {
    $error = smtpSend($config, $to, $message);
    $sent = $error === null;
    if (!$sent) {
        error_log('Cezareja obrazac, SMTP: ' . $error);
    }
}

if (!$sent) {
    respond(false, t('failed'), 500);
}

respond(true, t('sent'));
