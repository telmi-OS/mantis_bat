<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $services['config'];
$security = $services['security'];
$storage = $services['storage'];
$installerLocked = is_file(dirname(__DIR__) . '/storage/installed.lock');
$unlocked = ($_SESSION['private_mail_installer_unlocked'] ?? false) === true;
$completionPending = isset($_SESSION['private_mail_install_output']) && is_array($_SESSION['private_mail_install_output']);

if ($installerLocked && isset($_GET['unlock']) && is_string($_GET['unlock'])) {
    if ($security->verify($_GET['unlock'], (string) $config->get('app.installer_secret_hash', ''))) {
        session_regenerate_id(true);
        $_SESSION['private_mail_installer_unlocked'] = true;
        header('Location: install.php', true, 302);
        exit;
    }
}
if (!$installerLocked && $config->installed()) {
    http_response_code(404);
    exit('Not found.');
}
if ($installerLocked && !$unlocked && !$completionPending) {
    if (($_SESSION['private_mail_access'] ?? false) === true) {
        header('Location: index.php', true, 302);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['app_key']) && is_string($_POST['app_key'])
        && $security->equals((string)$config->get('app.access_secret', ''), trim($_POST['app_key']))) {
        session_regenerate_id(true);
        $_SESSION['private_mail_access'] = true;
        header('Location: index.php', true, 303);
        exit;
    }
    header('Cache-Control: no-store');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Open Private Mail</title><link rel="stylesheet" href="assets/theme.css"></head><body><main class="page-shell"><section class="card"><h1>Open Private Mail</h1><p class="copy">Enter the access token from the App URL saved during setup. It is the value after <code>key=</code>.</p><form method="post"><label>App access token<input type="password" name="app_key" autocomplete="off" required></label><button type="submit">Open Private Mail</button></form><p class="field-help">You can also open the complete saved App URL directly.</p></section></main></body></html><?php
    exit;
}

$_SESSION['private_mail_csrf'] ??= $security->token(24);
$_SESSION['private_mail_installer_secret'] ??= $security->token(16);
$csrf = (string) $_SESSION['private_mail_csrf'];
$installerSecret = (string) $_SESSION['private_mail_installer_secret'];
$error = '';
$notice = '';
$output = $_SESSION['private_mail_install_output'] ?? [];
unset($_SESSION['private_mail_install_output']);
$draft = $_SESSION['private_mail_install_draft'] ?? null;
$stage = is_array($draft) ? 'choose' : 'credentials';
$posted = $_POST;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $postedCsrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
        if (!$security->equals($csrf, $postedCsrf)) throw new RuntimeException('Your installer session expired. Reload this page and try again.');
        $action = (string) ($_POST['action'] ?? 'discover');
        if ($action === 'discover') {
            $appBase = rtrim(trim((string) ($_POST['app_base_url'] ?? '')), '/');
            $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Berlin'));
            $ownerName = trim((string) ($_POST['owner_name'] ?? ''));
            $ownerEmail = trim((string) ($_POST['owner_email'] ?? ''));
            $signature = isset($_POST['signature_enabled']) ? trim((string) ($_POST['signature'] ?? '')) : '';
            $imap = ['host'=>trim((string)($_POST['imap_host']??'')),'port'=>(int)($_POST['imap_port']??993),'username'=>trim((string)($_POST['imap_username']??'')),'password'=>(string)($_POST['imap_password']??'')];
            $smtp = ['host'=>trim((string)($_POST['smtp_host']??'')),'port'=>(int)($_POST['smtp_port']??465),'username'=>trim((string)($_POST['smtp_username']??'')),'password'=>(string)($_POST['smtp_password']??'')];
            $ghostBase = rtrim(trim((string)($_POST['ghost_api_base']??'')), '/');
            $ghostToken = trim((string)($_POST['ghost_api_token']??''));
            foreach ([$appBase,$ghostBase] as $url) {
                $parts=parse_url($url);
                if (!filter_var($url,FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
                    || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new RuntimeException('The app URL and Ghost API base must be absolute HTTPS URLs without credentials, query strings, or fragments.');
                }
            }
            try { new DateTimeZone($timezone); } catch (Throwable) { throw new RuntimeException('Choose a valid PHP timezone, such as Europe/Berlin or UTC.'); }
            if ($ownerName === '' || mb_strlen($ownerName)>120 || preg_match('/[\x00-\x1F\x7F]/u',$ownerName) || !filter_var($ownerEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter the owner’s name and a valid email address.');
            if ($imap['host']==='' || $imap['username']==='' || $imap['password']==='' || $imap['port']<1 || $imap['port']>65535) throw new RuntimeException('Complete the IMAP server, port, username, and password.');
            if ($smtp['host']==='' || $smtp['username']==='' || $smtp['password']==='' || $smtp['port']<1 || $smtp['port']>65535) throw new RuntimeException('Complete the SMTP server, port, username, and password.');
            if ($ghostToken==='') throw new RuntimeException('Enter the Ghost API v2 JWT.');
            $ghost = new MantisBat\PrivateMail\GhostClient($ghostBase,$ghostToken,$appBase);
            $ghost->probe();
            $groups = $ghost->groups();
            $allFileSpaces = $ghost->fileSpaces();
            foreach ($groups as &$group) {
                foreach ($allFileSpaces as $knownSpace) {
                    if (is_array($knownSpace) && (string)($knownSpace['group_id']??'')===(string)$group['id'] && trim((string)($knownSpace['label']??''))!=='') {
                        $group['label']=(string)$knownSpace['label'];break;
                    }
                }
            }
            unset($group);
            $spaces = array_values(array_filter($allFileSpaces,static fn($space):bool=>is_array($space)&&in_array((string)($space['group_role']??''),['owner','write'],true)));
            if ($spaces===[]) throw new RuntimeException('This Ghost JWT has no writable Files space. Check its Files access and group membership.');
            $imapClient = new MantisBat\PrivateMail\ImapClient($imap);
            try { $imapClient->connect(); $folders=$imapClient->folders(); }
            finally { $imapClient->close(); }
            if (!in_array('INBOX',$folders,true) && $folders===[]) throw new RuntimeException('The IMAP server did not return any mailbox folders.');
            MantisBat\PrivateMail\Smtp::probe(['smtp'=>$smtp,'owner'=>['email'=>$ownerEmail]]);
            $_SESSION['private_mail_install_draft'] = [
                'app'=>['base_url'=>$appBase,'timezone'=>$timezone],
                'owner'=>['name'=>$ownerName,'email'=>$ownerEmail],
                'signature'=>$signature,
                'imap'=>$imap,'smtp'=>$smtp,
                'ghost'=>['api_base'=>$ghostBase,'api_token'=>$ghostToken,'use_rag'=>isset($_POST['use_rag']),'use_history'=>isset($_POST['use_history'])],
                'groups'=>$groups,'spaces'=>$spaces,'folders'=>$folders,
            ];
            $draft=$_SESSION['private_mail_install_draft'];
            $stage='choose';
            $notice='Ghost API, mailbox folders, SMTP connection, and writable Files spaces are available. Choose the folder roles and notification destination.';
        } elseif ($action === 'install') {
            $draft = $_SESSION['private_mail_install_draft'] ?? null;
            if (!is_array($draft)) throw new RuntimeException('Check the connections and discover mailbox folders before installing.');
            $folderChoices = (array)($draft['folders']??[]);
            $inboxName = (string)($_POST['folder_inbox']??'');
            $sentName = (string)($_POST['folder_sent']??'');
            $deletedName = trim((string)($_POST['folder_deleted']??'Ghost Deleted'));
            if (!in_array($inboxName,$folderChoices,true) || !in_array($sentName,$folderChoices,true) || $inboxName===$sentName) throw new RuntimeException('Choose different server folders for INBOX and Sent from the discovered list.');
            if ($deletedName==='' || preg_match('/[\r\n\x00]/',$deletedName) || in_array($deletedName,[$inboxName,$sentName],true)) throw new RuntimeException('Ghost Deleted needs a separate server folder name.');
            $spaceId=(string)($_POST['files_space_id']??'');
            $space=null;
            foreach((array)$draft['spaces'] as $candidate) if(is_array($candidate)&&(string)($candidate['space_id']??'')===$spaceId&&in_array((string)($candidate['group_role']??''),['owner','write'],true))$space=$candidate;
            if(!is_array($space))throw new RuntimeException('Choose one of the writable Files spaces discovered for this Ghost.');
            $destinationChoice=(string)($_POST['destination']??'owner');
            if($destinationChoice==='owner')$destination=['type'=>'owner','id'=>'','label'=>(string)$draft['owner']['name']];
            elseif(str_starts_with($destinationChoice,'group:')){
                $groupId=substr($destinationChoice,6);$group=null;
                foreach((array)$draft['groups'] as $candidate)if(is_array($candidate)&&(string)($candidate['id']??'')===$groupId)$group=$candidate;
                if(!is_array($group))throw new RuntimeException('Choose the owner or an available Ghost group as the notification destination.');
                $destination=['type'=>'group','id'=>$groupId,'label'=>(string)$group['label']];
            }else throw new RuntimeException('Choose the owner or an available Ghost group as the notification destination.');
            if(trim((string)$destination['label'])==='' || ((string)$destination['id']!=='' && (string)$destination['label']===(string)$destination['id']) || preg_match('/[\x00-\x1F\x7F]/u',(string)$destination['label'])) throw new RuntimeException('The selected destination has no usable name. Choose the owner or a group with a name.');

            $configData=[
                'app'=>[
                    'installed'=>false,'base_url'=>(string)$draft['app']['base_url'],'timezone'=>(string)$draft['app']['timezone'],'version'=>'0.1.0',
                    'api_key'=>$security->token(32),'access_secret'=>$security->token(32),'cron_secret'=>$security->token(24),
                    'status_secret'=>$security->token(24),'health_secret'=>$security->token(24),
                    'installer_secret_hash'=>$security->hash($installerSecret),
                ],
                'owner'=>$draft['owner'],'signature'=>(string)$draft['signature'],
                'imap'=>$draft['imap'],'smtp'=>$draft['smtp'],
                'ghost'=>['api_base'=>$draft['ghost']['api_base'],'api_token'=>$draft['ghost']['api_token']],
                'files'=>['space_id'=>$spaceId,'space_label'=>(string)($space['label']??$spaceId),'folder_id'=>''],
                'mail'=>['folders'=>['inbox'=>$inboxName,'sent'=>$sentName,'ghost_deleted'=>$deletedName]],
                'rules'=>['default'=>'summarize_notify'],
                'processing'=>['use_rag'=>(bool)$draft['ghost']['use_rag'],'use_history'=>(bool)$draft['ghost']['use_history'],'batch_size'=>10],
                'notifications'=>['destination'=>$destination],
            ];
            $config->write($configData);
            $storage->setFolder('inbox',$inboxName);$storage->setFolder('sent',$sentName);$storage->setFolder('ghost_deleted',$deletedName);
            $imapClient = new MantisBat\PrivateMail\ImapClient((array)$configData['imap']);
            try { $imapClient->connect(); $imapClient->ensureFolder($deletedName); }
            finally { $imapClient->close(); }
            $sync = new MantisBat\PrivateMail\ImapSync($config,$storage);
            $baselineCount=0;
            $syncResult=['complete'=>false];
            for($attempt=0;$attempt<1000&&!($syncResult['complete']??false);$attempt++){
                $syncResult=$sync->sync(1000);
                $baselineCount+=(int)($syncResult['new_messages']??0);
            }
            if(!($syncResult['complete']??false))throw new RuntimeException('Initial mailbox baseline is larger than one installer run can finish. Retry installation; existing sync progress is saved privately.');
            $ghost = new MantisBat\PrivateMail\GhostClient((string)$draft['ghost']['api_base'],(string)$draft['ghost']['api_token'],(string)$draft['app']['base_url']);
            $backup = $ghost->installPresets((string)$configData['app']['api_key'],dirname(__DIR__).'/storage');
            $lockPath=dirname(__DIR__).'/storage/installed.lock';
            if(file_put_contents($lockPath,gmdate(DATE_ATOM),LOCK_EX)===false)throw new RuntimeException('Private Mail installed, but the installer lock could not be written.');
            @chmod($lockPath,0600);
            $configData['app']['installed']=true;
            $config->write($configData);
            $base=(string)$configData['app']['base_url'];
            $output=[
                'App URL'=>$base.'/index.php?key='.rawurlencode((string)$configData['app']['access_secret']),
                'Cron URL'=>$base.'/cron.php?key='.rawurlencode((string)$configData['app']['cron_secret']),
                'Status URL'=>$base.'/status.php?key='.rawurlencode((string)$configData['app']['status_secret']),
                'Health URL'=>$base.'/health.php?key='.rawurlencode((string)$configData['app']['health_secret']),
                'Installer unlock URL'=>$base.'/install.php?unlock='.rawurlencode($installerSecret),
                'Ghost settings backup'=>$backup,
                'Initial mailbox baseline'=>$baselineCount.' messages; no summaries, notifications, replies, or drafts were triggered.',
            ];
            $_SESSION['private_mail_install_output']=$output;
            $_SESSION['private_mail_access']=true;
            unset($_SESSION['private_mail_install_draft'],$_SESSION['private_mail_installer_secret'],$_SESSION['private_mail_installer_unlocked']);
            header('Location: install.php',true,303);exit;
        } else throw new RuntimeException('Unknown installer action.');
    } catch (Throwable $exception) {
        $error=$exception->getMessage();
        $draft=$_SESSION['private_mail_install_draft']??null;
        $stage=is_array($draft)?'choose':'credentials';
    }
}

$groups=is_array($draft)?(array)($draft['groups']??[]):[];
$spaces=is_array($draft)?(array)($draft['spaces']??[]):[];
$folders=is_array($draft)?(array)($draft['folders']??[]):[];
$defaultFolder=static function(array $choices,array $needles,string $fallback=''):string{foreach($choices as $choice)foreach($needles as $needle)if(stripos((string)$choice,$needle)!==false)return (string)$choice;return in_array($fallback,$choices,true)?$fallback:(string)($choices[0]??'');};
$defaultInbox=$defaultFolder($folders,['inbox'],'INBOX');
$defaultSent=$defaultFolder($folders,['sent','sent items','sent mail']);
$defaultDeleted=$defaultFolder($folders,['ghost deleted'],'Ghost Deleted');
function privateMailInstallH(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Private Mail Setup | Mantis Bat</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="page-shell">
<header class="hero"><div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Standalone Mantis Bat Connector</p><h1><span class="gradient-text">Private Mail</span> setup</h1></div></div><p class="copy">Connect one mailbox, choose how new mail is handled, and install the private-mail presets into your Ghost.</p></header>
<?php if($output): ?><section class="card"><h2><span class="gradient-text">Installation complete</span></h2><p>Save these private URLs. Configure Hades cron to call the Cron URL once per minute.</p><?php foreach($output as $label=>$value):?><p><strong><?=privateMailInstallH((string)$label)?></strong><br><code><?=privateMailInstallH((string)$value)?></code></p><?php endforeach?><p class="field-help">Keep the App, Cron, Status, Health, and Installer unlock URLs private. The initial sync created a quiet mailbox baseline.</p></section><?php endif?>
<?php if($error!==''):?><section class="card alert-card"><h2>Setup issue</h2><p><?=privateMailInstallH($error)?></p></section><?php endif?>
<section class="card requirements"><h2>Requirements</h2><p>PHP 8.1+, cURL, PDO SQLite, OpenSSL, mbstring, iconv, HTTPS, writable private storage, and outbound IMAP, SMTP, Ghost API, and Ghost Files connections.</p><p>Expose only the staged public directory. Keep module <code>src/</code> and <code>storage/</code> private.</p></section>
<?php if($stage==='choose'&&is_array($draft)):?>
<section class="card"><h2><span class="gradient-text">Choose mailbox and destination</span></h2><p class="copy"><?=privateMailInstallH($notice)?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=privateMailInstallH($csrf)?>"><input type="hidden" name="action" value="install">
<label>INBOX server folder<select name="folder_inbox" required><?php foreach($folders as $folder):?><option value="<?=privateMailInstallH((string)$folder)?>" <?=$folder===$defaultInbox?'selected':''?>><?=privateMailInstallH((string)$folder)?></option><?php endforeach?></select></label>
<label>Sent server folder<select name="folder_sent" required><?php foreach($folders as $folder):?><option value="<?=privateMailInstallH((string)$folder)?>" <?=$folder===$defaultSent?'selected':''?>><?=privateMailInstallH((string)$folder)?></option><?php endforeach?></select><span class="field-help">Provider folder names vary. Choose the server folder that holds sent messages.</span></label>
<label>Ghost Deleted server folder<input name="folder_deleted" value="<?=privateMailInstallH($defaultDeleted)?>" required><span class="field-help">Private Mail creates this folder if it does not exist. It is separate from INBOX and Sent.</span></label>
<label>Ghost Files space<select name="files_space_id" required><option value="">Choose a writable space</option><?php foreach($spaces as $space):?><option value="<?=privateMailInstallH((string)($space['space_id']??''))?>"><?=privateMailInstallH((string)($space['label']??$space['space_id']??'Files space'))?> · <?=privateMailInstallH((string)($space['group_role']??''))?></option><?php endforeach?></select><span class="field-help">Requested attachments are uploaded here after explicit requests.</span></label>
<label>Notification and approval destination<select name="destination" required><option value="owner">Owner · <?=privateMailInstallH((string)$draft['owner']['name'])?></option><?php foreach($groups as $group):?><option value="group:<?=privateMailInstallH((string)($group['id']??''))?>">Group · <?=privateMailInstallH((string)($group['label']??$group['id']??''))?></option><?php endforeach?></select><span class="field-help">Choose one destination for new-mail notices and direct-chat approvals.</span></label>
<button type="submit">Install Private Mail and sync baseline</button></form></section>
<?php elseif(!$output):?>
<section class="card"><h2><span class="gradient-text">Connect accounts</span></h2><form method="post"><input type="hidden" name="csrf" value="<?=privateMailInstallH($csrf)?>"><input type="hidden" name="action" value="discover">
<h3>Application and owner</h3><label>Public app base URL<input name="app_base_url" value="<?=privateMailInstallH((string)($posted['app_base_url']??''))?>" placeholder="https://example.com/private-mail" required></label><label>Timezone<input name="timezone" value="<?=privateMailInstallH((string)($posted['timezone']??'Europe/Berlin'))?>" required></label><label>Your name<input name="owner_name" value="<?=privateMailInstallH((string)($posted['owner_name']??''))?>" required></label><label>Your email<input type="email" name="owner_email" value="<?=privateMailInstallH((string)($posted['owner_email']??''))?>" required></label><label class="check-row"><input type="checkbox" name="signature_enabled" value="1" <?=isset($posted['signature_enabled'])?'checked':''?>> Add a text signature</label><label>Signature<textarea name="signature" rows="3"><?=privateMailInstallH((string)($posted['signature']??''))?></textarea></label>
<h3>IMAP</h3><div class="form-grid"><label>IMAP host<input name="imap_host" value="<?=privateMailInstallH((string)($posted['imap_host']??''))?>" required></label><label>Port<input type="number" name="imap_port" value="<?=privateMailInstallH((string)($posted['imap_port']??'993'))?>" required></label></div><label>Username<input name="imap_username" value="<?=privateMailInstallH((string)($posted['imap_username']??''))?>" required></label><label>Password<input type="password" name="imap_password" autocomplete="new-password" required></label>
<h3>SMTP</h3><div class="form-grid"><label>SMTP host<input name="smtp_host" value="<?=privateMailInstallH((string)($posted['smtp_host']??''))?>" required></label><label>Port<input type="number" name="smtp_port" value="<?=privateMailInstallH((string)($posted['smtp_port']??'465'))?>" required></label></div><label>Username<input name="smtp_username" value="<?=privateMailInstallH((string)($posted['smtp_username']??''))?>" required></label><label>Password<input type="password" name="smtp_password" autocomplete="new-password" required></label>
<h3>Ghost API v2</h3><label>Ghost API base<input name="ghost_api_base" value="<?=privateMailInstallH((string)($posted['ghost_api_base']??'https://dev.telmi-ai.com/api/ghost/v2'))?>" required></label><label>Ghost JWT<input type="password" name="ghost_api_token" autocomplete="new-password" required><span class="field-help">Stored only in private server configuration.</span></label><label class="check-row"><input type="checkbox" name="use_rag" value="1" <?=!isset($posted['action'])||isset($posted['use_rag'])?'checked':''?>> Use Ghost RAG for private-mail processing</label><label class="check-row"><input type="checkbox" name="use_history" value="1" <?=!isset($posted['action'])||isset($posted['use_history'])?'checked':''?>> Use Ghost chat history independently</label>
<label>Installer unlock secret<input type="password" value="<?=privateMailInstallH($installerSecret)?>" readonly><span class="field-help">The protected installer unlock URL is shown after setup.</span></label><button type="submit">Check connections and find mailbox folders</button></form></section>
<?php endif?>
</main><div class="setup-loading" id="setup-loading" hidden role="status" aria-live="polite"><div class="setup-loading-card"><span class="setup-spinner" aria-hidden="true"></span><strong id="setup-loading-text">Working…</strong><p>Please keep this page open. Mailbox synchronization can take several minutes.</p></div></div><script>
document.querySelectorAll('form[method="post"]').forEach(function(form) {
    form.addEventListener('submit', function() {
        if (!form.checkValidity()) return;
        var action = form.querySelector('input[name="action"]');
        document.getElementById('setup-loading-text').textContent = action && action.value === 'install' ? 'Installing Private Mail and syncing your mailbox…' : 'Checking connections and discovering folders…';
        document.getElementById('setup-loading').hidden = false;
        form.querySelectorAll('button[type="submit"]').forEach(function(button) { button.disabled = true; });
    });
});
window.addEventListener('pageshow', function(event) { if (event.persisted) { document.getElementById('setup-loading').hidden = true; document.querySelectorAll('button[type="submit"]').forEach(function(button) { button.disabled = false; }); } });
</script></body></html>
