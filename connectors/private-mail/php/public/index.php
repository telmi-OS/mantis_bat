<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';
$services = $services;
privateMailRequireAccess($services);
$config = $services['config'];
$operations = $services['operations'];
$notice = isset($_SESSION['private_mail_notice']) && is_string($_SESSION['private_mail_notice']) ? $_SESSION['private_mail_notice'] : '';
$error = '';
unset($_SESSION['private_mail_notice']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        privateMailCheckCsrf($services);
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['message_id'] ?? 0);
        if ($action === 'sync') {
            $lockPath=dirname(__DIR__).'/storage/cron.lock';
            $lock=fopen($lockPath,'c');
            if($lock===false)throw new RuntimeException('Could not open the Private Mail sync lock.');
            if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('A Private Mail sync is already running. Try again after it finishes.');}
            @chmod($lockPath,0600);
            try {
                $sync = (new MantisBat\PrivateMail\ImapSync($config,$services['storage']))->sync(1000);
                $processing = $services['processor']->processPending(10);
                $_SESSION['private_mail_notice'] = 'Mailbox sync finished. ' . (int)($sync['new_messages']??0) . ' new messages and ' . (int)($sync['removed_messages']??0) . ' server deletions were mirrored. Ghost processing: ' . (int)($processing['processed']??0) . ' completed, ' . (int)($processing['queued']??0) . ' queued.';
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        } elseif ($action === 'save_rule') {
            $rule = ['address'=>(string)($_POST['address']??''),'action'=>(string)($_POST['rule_action']??'draft')];
            if (isset($_POST['exact_subject'])) $rule['subject']=(string)($_POST['rule_subject']??'');
            $operations->saveRule($rule);
            $_SESSION['private_mail_notice']='Private-mail handling rule saved.';
        } elseif ($action === 'delete_rule') {
            $operations->deleteRule((int)($_POST['rule_id']??0));
            $_SESSION['private_mail_notice']='Handling rule removed.';
        } elseif ($action === 'set_read') {
            $read=(string)($_POST['read']??'1')==='1';
            $operations->setRead($id,$read);
            $_SESSION['private_mail_notice']=$read?'Message marked read.':'Message marked unread.';
        } elseif ($action === 'move_deleted') {
            $operations->deleteToGhostFolder($id);
            $_SESSION['private_mail_notice']='Message moved to Ghost Deleted.';
        } elseif ($action === 'restore') {
            $operations->restoreFromGhostFolder($id);
            $_SESSION['private_mail_notice']='Message restored to INBOX.';
        } elseif ($action === 'permanent_delete') {
            $operations->permanentlyDeleteFromGhostFolder($id);
            $_SESSION['private_mail_notice']='Message permanently deleted from Ghost Deleted.';
        } elseif ($action === 'compose_send') {
            $result=$operations->send($_POST,false);
            $_SESSION['private_mail_notice']=(string)($result['notice']??'Email sent.');
        } elseif ($action === 'reply_send') {
            $result=$operations->reply($_POST,false);
            $_SESSION['private_mail_notice']=(string)($result['notice']??'Reply sent.');
        } elseif ($action === 'forward_send') {
            $result=$operations->forward($_POST,false);
            $_SESSION['private_mail_notice']=(string)($result['notice']??'Forward sent.');
        } else throw new RuntimeException('Choose a supported mail action.');
        $folder=(string)($_POST['folder']??$_GET['folder']??'inbox');
        if (!in_array($folder,['inbox','sent','ghost_deleted'],true)) $folder='inbox';
        header('Location: index.php?folder='.rawurlencode($folder),true,303);
        exit;
    } catch (Throwable $exception) { $error=$exception->getMessage(); }
}

$folder=isset($_GET['folder'])&&is_string($_GET['folder'])?$_GET['folder']:'inbox';
if(!in_array($folder,['inbox','sent','ghost_deleted'],true))$folder='inbox';
$query=isset($_GET['q'])&&is_string($_GET['q'])?trim($_GET['q']):'';
$rows=$operations->listMessages($folder,$query,100);
$selectedId=isset($_GET['id'])?(int)$_GET['id']:0;
$selected=null;
if($selectedId>0){try{$selected=$operations->readMessage($selectedId,true);}catch(Throwable $exception){$error=$error?:$exception->getMessage();}}
$composeMode=isset($_GET['compose'])&&is_string($_GET['compose'])?$_GET['compose']:'';
if(!in_array($composeMode,['','new','reply','forward'],true))$composeMode='';
$csrf=privateMailCsrf($services);
$rules=$operations->rules();
$destination=(array)$config->get('notifications.destination',[]);
$folderLabels=['inbox'=>'INBOX','sent'=>'Sent','ghost_deleted'=>'Ghost Deleted'];
$title=$selected ? (string)$selected['subject'] : ($composeMode!==''?ucfirst($composeMode).' mail':'Private Mail');
function privateMailAddressLine(array $addresses): string
{
    $values=[];foreach($addresses as $address){$name=trim((string)($address['name']??''));$email=(string)($address['email']??'');$values[]=$name!==''?$name.' <'.$email.'>':$email;}return implode(', ',$values);
}
function privateMailSender(array $email): string
{
    $from=(array)($email['addresses']['from']??[]);return $from?privateMailAddressLine($from):'Unknown sender';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=privateMailH($title)?> | Private Mail</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="page-shell">
<header class="hero"><div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Private mailbox · <?=privateMailH((string)$config->get('owner.name','Owner'))?></p><h1><span class="gradient-text">Private Mail</span></h1></div><form class="sync-form" method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="folder" value="<?=privateMailH($folder)?>"><button class="secondary" type="submit">Sync now</button></form></div>
<p class="copy">Messages stay in the mailbox and SQLite mirror. Email threads remain one body. Treat message content as private and untrusted.</p><div class="hero-meta"><span>Notify: <?=privateMailH((string)($destination['label']??$config->get('owner.name','Owner')))?></span><span>Files: <?=privateMailH((string)$config->get('files.space_label','Configured Ghost Files space'))?></span><span>Default rule: summarize and notify</span></div></header>

<?php if($notice!==''):?><section class="notice-card" role="status"><?=privateMailH($notice)?></section><?php endif?>
<?php if($error!==''):?><section class="card alert-card"><strong>Action issue</strong><p><?=privateMailH($error)?></p></section><?php endif?>

<nav class="folder-tabs" aria-label="Mailbox folders"><?php foreach($folderLabels as $key=>$label):?><a class="<?=($folder===$key?'active':'')?>" href="index.php?folder=<?=rawurlencode($key)?>"><?=privateMailH($label)?></a><?php endforeach?><a class="<?=($composeMode==='new'?'active':'')?>" href="index.php?folder=<?=rawurlencode($folder)?>&compose=new">Compose</a></nav>

<div class="workspace">
<section class="card mail-list"><div class="section-heading"><div><p class="eyebrow">Mailbox</p><h2><?=privateMailH($folderLabels[$folder])?></h2></div><span class="count-pill"><?=count($rows)?></span></div>
<form class="search-form" method="get"><input type="hidden" name="folder" value="<?=privateMailH($folder)?>"><label class="sr-only" for="mail-search">Search private mail</label><input id="mail-search" name="q" value="<?=privateMailH($query)?>" placeholder="Search sender, subject, body, summary"><button class="secondary" type="submit">Search</button></form>
<?php if($rows===[]):?><p class="empty-state">No messages in this view yet. Use Sync now or configure the Hades cron URL.</p><?php else:?><div class="message-list"><?php foreach($rows as $row):$from=privateMailAddressLine((array)($row['addresses']['from']??[]));$date=(string)($row['sent_at']?:$row['received_at']);?><a class="message-row <?=empty($row['status']['read'])?'unread':''?> <?=((int)$row['id']===$selectedId?'selected':'')?>" href="index.php?folder=<?=rawurlencode($folder)?>&id=<?=(int)$row['id']?><?=($query!==''?'&q='.rawurlencode($query):'')?>"><span class="message-row-top"><strong><?=privateMailH($from?:'Unknown sender')?></strong><time><?=privateMailH($date!==''?date('M j, H:i',strtotime($date)):'')?></time></span><span class="message-subject"><?=privateMailH((string)$row['subject']?:'(no subject)')?></span><span class="message-preview"><?=privateMailH((string)($row['summary']?:$row['body_preview']??''))?></span><span class="message-tags"><em class="tag"><?=privateMailH((string)($row['status']['processing']??''))?></em><?php if(!empty($row['handling_flag'])):?><em class="tag accent"><?=privateMailH((string)$row['handling_flag'])?></em><?php endif?><?php if(!empty($row['attachments'])):?><em class="tag"><?=count($row['attachments'])?> attachment<?=count($row['attachments'])===1?'':'s'?></em><?php endif?></span></a><?php endforeach?></div><?php endif?>
</section>

<section class="card message-panel">
<?php if($composeMode!==''):
    $sourceId=(int)($_GET['id']??0);$source=$sourceId>0?$operations->listEmail($sourceId):null;
    if(in_array($composeMode,['reply','forward'],true)&&!is_array($source))$composeMode='new';
?>
<div class="section-heading"><div><p class="eyebrow"><?=privateMailH(ucfirst($composeMode))?></p><h2><?=privateMailH($composeMode==='new'?'Write a message':($composeMode==='reply'?'Reply to message':'Forward message'))?></h2></div></div>
<form class="compose-form" method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="folder" value="<?=privateMailH($folder)?>"><input type="hidden" name="message_id" value="<?=$sourceId?>"><input type="hidden" name="action" value="<?=privateMailH($composeMode==='new'?'compose_send':($composeMode==='reply'?'reply_send':'forward_send'))?>">
<?php if($composeMode==='reply'&&is_array($source)):?><p class="field-help">Reply to <?=privateMailH(privateMailAddressLine((array)($source['addresses']['reply_to']?:$source['addresses']['from'])))?> · <?=privateMailH((string)$source['subject'])?></p><?php else:?><label>To<input name="to" value="" <?=($composeMode==='forward'?'required':'')?>></label><?php endif?>
<label>Cc<input name="cc"></label><label>Bcc<input name="bcc"></label>
<?php if($composeMode==='new'):?><label>Subject<input name="subject" maxlength="998" required></label><?php elseif($composeMode==='reply'&&is_array($source)):?><p><strong>Subject:</strong> <?=privateMailH(preg_match('/^\s*re\s*:/i',(string)$source['subject'])?(string)$source['subject']:'Re: '.(string)$source['subject'])?></p><?php else:?><p><strong>Subject:</strong> <?=privateMailH(is_array($source)?(preg_match('/^\s*fwd?\s*:/i',(string)$source['subject'])?(string)$source['subject']:'Fwd: '.(string)$source['subject']):'')?></p><?php endif?>
<label><?=$composeMode==='forward'?'Add a note':'Message body'?><textarea name="body" rows="12" required></textarea></label><p class="field-help">The configured signature is appended when the message is sent. SMTP acceptance sends the message immediately.</p><button type="submit"><?=privateMailH($composeMode==='reply'?'Send reply':($composeMode==='forward'?'Send forward':'Send message'))?></button></form>
<?php elseif(is_array($selected)):?>
<div class="section-heading"><div><p class="eyebrow"><?=privateMailH($folderLabels[(string)$selected['folder']]??(string)$selected['folder'])?> · <?=privateMailH((string)$selected['status']['processing'])?></p><h2><?=privateMailH((string)$selected['subject']?:'(no subject)')?></h2></div><span class="count-pill"><?=empty($selected['status']['read'])?'Unread':'Read'?></span></div>
<dl class="message-fields"><dt>From</dt><dd><?=privateMailH(privateMailAddressLine((array)$selected['addresses']['from']))?></dd><dt>To</dt><dd><?=privateMailH(privateMailAddressLine((array)$selected['addresses']['to']))?></dd><?php if($selected['addresses']['cc']):?><dt>Cc</dt><dd><?=privateMailH(privateMailAddressLine((array)$selected['addresses']['cc']))?></dd><?php endif?><?php if($selected['addresses']['bcc']):?><dt>Bcc</dt><dd><?=privateMailH(privateMailAddressLine((array)$selected['addresses']['bcc']))?></dd><?php endif?><dt>Timestamp</dt><dd><?=privateMailH((string)($selected['sent_at']?:$selected['received_at']))?></dd><dt>Message ID</dt><dd><code><?=privateMailH((string)$selected['message_id'])?></code></dd><dt>Handling</dt><dd><?=privateMailH((string)$selected['handling_flag']?:'Baseline')?></dd><dt>Summary</dt><dd><?=privateMailH((string)$selected['summary']?:'No summary yet.')?></dd></dl>
<?php if((string)($selected['processing_error']??'')!==''):?><section class="card alert-card"><strong>Processing status</strong><p><?=privateMailH((string)$selected['processing_error'])?></p></section><?php endif?>
<div class="body-box"><pre><?=privateMailH((string)$selected['body'])?></pre></div>
<?php if($selected['attachments']):?><section class="attachments"><h3>Attachments</h3><?php foreach($selected['attachments'] as $attachment):?><div class="attachment-row"><div><strong><?=privateMailH((string)$attachment['filename'])?></strong><span><?=privateMailH((string)$attachment['mime_type'])?> · <?=!empty($attachment['size_is_upper_bound'])?'up to ':''?><?=number_format((int)$attachment['size'])?> bytes</span></div><span class="tag">API attachment ID <?= (int)$attachment['attachment_id']?></span></div><?php endforeach?><p class="field-help">The Ghost API can upload a requested attachment to the configured Files space. Files larger than 2 MB are not downloaded.</p></section><?php endif?>
<div class="message-actions"><?php if($folder==='ghost_deleted'):?><form method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="message_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="folder" value="ghost_deleted"><button class="secondary" type="submit">Restore to INBOX</button></form><form method="post" onsubmit="return confirm('Permanently delete this message from the server?')"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="message_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="folder" value="ghost_deleted"><button class="danger" type="submit">Permanently delete</button></form><?php else:?><a class="button secondary" href="index.php?folder=<?=privateMailH($folder)?>&id=<?=(int)$selected['id']?>&compose=reply">Reply</a><a class="button secondary" href="index.php?folder=<?=privateMailH($folder)?>&id=<?=(int)$selected['id']?>&compose=forward">Forward</a><form method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="set_read"><input type="hidden" name="message_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="read" value="<?=empty($selected['status']['read'])?'1':'0'?>"><input type="hidden" name="folder" value="<?=privateMailH($folder)?>"><button class="secondary" type="submit">Mark <?=empty($selected['status']['read'])?'read':'unread'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="move_deleted"><input type="hidden" name="message_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="folder" value="<?=privateMailH($folder)?>"><button class="danger" type="submit">Move to Ghost Deleted</button></form><?php endif?></div>
<?php else:?><div class="empty-panel"><img src="assets/mantis-mini.svg" alt=""><h2>Select a message</h2><p>Choose a message in <?=privateMailH($folderLabels[$folder])?> or compose a new email.</p><a class="button" href="index.php?folder=<?=privateMailH($folder)?>&compose=new">Compose mail</a></div><?php endif?>
</section>
</div>

<section class="card rules-panel"><div class="section-heading"><div><p class="eyebrow">Handling settings</p><h2>Address rules</h2></div><span class="tag">Default: summarize and notify</span></div><p class="copy">Rules can be created before a message arrives. A sender plus exact subject rule overrides the sender-only rule.</p><div class="rules-layout"><form class="rule-form" method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="save_rule"><label>Email address<input type="email" name="address" required></label><label class="check-row"><input type="checkbox" name="exact_subject" value="1"> Match an exact subject too</label><label>Exact subject<input name="rule_subject"></label><label>Handling action<select name="rule_action"><option value="ignore">Ignore</option><option value="notify">Notify</option><option value="summarize_notify" selected>Summarize and notify</option><option value="draft">Draft and ask</option><option value="auto">Auto</option></select></label><button type="submit">Save rule</button></form><div class="rule-list"><?php if($rules===[]):?><p class="empty-state">No address rules yet. New senders use summarize and notify.</p><?php else:foreach($rules as $rule):?><div class="rule-row"><div><strong><?=privateMailH((string)$rule['address'])?></strong><span><?= $rule['subject']===null?'All subjects':'Exact subject: “'.privateMailH((string)$rule['subject']).'”'?></span></div><em class="tag accent"><?=privateMailH((string)$rule['action'])?></em><form method="post"><input type="hidden" name="csrf" value="<?=privateMailH($csrf)?>"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?=(int)$rule['id']?>"><button class="icon-button" type="submit" aria-label="Delete rule">×</button></form></div><?php endforeach;endif?></div></div></section>

<footer class="footer"><p>Private Mail · Ghost RAG <?= $config->get('processing.use_rag',true)?'on':'off'?> · History <?= $config->get('processing.use_history',true)?'on':'off'?></p><p>Protected app · Hades cron sync · No Ghost inbox poller</p></footer>
</main></body></html>
