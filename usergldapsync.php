#!/usr/bin/php
<?php
// first code, from ChatGPT

$ldapHost = "ldaps://ldap.google.com";
$baseDN = "dc=example,dc=com";
$fields = "uid cn uidNumber gidNumber homeDirectory loginShell";
$certs = [
    'LDAPTLS_CERT' => '/etc/ssl/certs/client_cert.pem',
    'LDAPTLS_KEY' => '/etc/ssl/private/client_key.pem',
    'LDAPTLS_CACERT' => '/etc/ssl/certs/google_root_ca.pem',
];
$defaultShell = "/bin/bash";
$dryRun = false;
$filterUid = null;
$logFile = "/var/log/ldap_user_import.log";

// --- Options CLI --- //
$options = getopt("", ["dry-run", "uid::", "help"]);
if (isset($options["help"])) {
    echo "Usage: php ldap_user_import.php [--dry-run] [--uid=USERNAME]\n";
    exit(0);
}
if (isset($options["dry-run"])) $dryRun = true;
if (isset($options["uid"])) $filterUid = $options["uid"];

// --- Log helper --- //
function log_action($msg) {
    global $logFile;
    $entry = "[" . date("Y-m-d H:i:s") . "] $msg\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
    echo $msg . "\n";
}

// --- LDAP Search --- //
$filter = "(objectClass=inetOrgPerson)";
if ($filterUid) $filter = "(&(objectClass=inetOrgPerson)(uid=$filterUid))";

$envString = "";
foreach ($certs as $k => $v) $envString .= "$k=\"$v\" ";
$ldapCmd = "{$envString} ldapsearch -LLL -H {$ldapHost} -b \"{$baseDN}\" \"{$filter}\" {$fields}";

log_action("[*] Commande LDAP : $ldapCmd");
exec($ldapCmd, $output, $retCode);
if ($retCode !== 0) {
    log_action("[!] ldapsearch a échoué avec le code $retCode");
    exit(1);
}

// --- Parsing --- //
$users = [];
$current = [];
foreach ($output as $line) {
    $line = trim($line);
    if ($line === '') {
        if (!empty($current)) {
            $users[] = $current;
            $current = [];
        }
        continue;
    }
    if (strpos($line, ':') !== false) {
        list($key, $val) = explode(':', $line, 2);
        $current[trim($key)] = trim($val);
    }
}
if (!empty($current)) $users[] = $current;

// --- Traitement --- //
foreach ($users as $user) {
    if (empty($user['uid']) || empty($user['uidNumber']) || empty($user['gidNumber'])) {
        log_action("[!] Champ manquant pour un utilisateur, ignoré.");
        continue;
    }

    $uid = $user['uid'];
    $uidNum = $user['uidNumber'];
    $gidNum = $user['gidNumber'];
    $home = $user['homeDirectory'] ?? "/home/$uid";
    $shell = $user['loginShell'] ?? $defaultShell;
    $comment = $user['cn'] ?? $uid;

    exec("getent passwd $uid", $exists);
    if (!empty($exists)) {
        log_action("[=] $uid existe déjà, ignoré.");
        continue;
    }

    $userAddCmd = "useradd -u $uidNum -g $gidNum -d $home -s $shell -c \"$comment\" $uid";
    $passwdCmd = "passwd -d $uid";
    $mkdirCmd = "mkdir -p $home && chown $uid:$gidNum $home && chmod 755 $home";

    if ($dryRun) {
        log_action("[DRY] ➤ $userAddCmd");
        log_action("[DRY] ➤ $mkdirCmd");
        log_action("[DRY] ➤ $passwdCmd");
    } else {
        log_action("[+] Création de l'utilisateur $uid...");
        exec($userAddCmd, $out1, $code1);
        if ($code1 === 0) {
            log_action("    ✅ useradd OK");
            exec($mkdirCmd, $out2, $code2);
            if ($code2 === 0) {
                log_action("    ✅ home OK : $home");
            } else {
                log_action("    ⚠️ Erreur création home : code $code2");
            }
            exec($passwdCmd, $out3, $code3);
            if ($code3 === 0) {
                log_action("    🔒 Mot de passe désactivé.");
            } else {
                log_action("    ⚠️ Erreur désactivation mot de passe.");
            }
        } else {
            log_action("    ❌ Erreur useradd (code $code1)");
        }
    }
}
