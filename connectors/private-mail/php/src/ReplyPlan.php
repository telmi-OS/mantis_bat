<?php
declare(strict_types=1);
namespace MantisBat\PrivateMail;

final class ReplyPlan
{
    public static function create(array $email, string $owner): array
    {
        $groups=['reply_to'=>[], 'from'=>[], 'to'=>[], 'cc'=>[]];
        foreach ((array)$email['addresses'] as $address) {
            $kind=(string)($address['kind']??'');
            if (isset($groups[$kind])) $groups[$kind][]=strtolower(trim((string)$address['address']));
        }
        $owner=strtolower(trim($owner)); $seen=[$owner=>true]; $to=[]; $cc=[];
        foreach (array_merge($groups['reply_to'] ?: $groups['from'], $groups['to']) as $address) {
            if (filter_var($address,FILTER_VALIDATE_EMAIL) && !isset($seen[$address])) { $seen[$address]=true; $to[]=$address; }
        }
        foreach ($groups['cc'] as $address) {
            if (filter_var($address,FILTER_VALIDATE_EMAIL) && !isset($seen[$address])) { $seen[$address]=true; $cc[]=$address; }
        }
        if ($to===[]) throw new \RuntimeException('Reply-all has no recipient other than the owner.');
        $subject=(string)$email['subject'];
        if (!preg_match('/^\s*re\s*:/i',$subject)) $subject='Re: '.$subject;
        return ['to'=>$to,'cc'=>$cc,'bcc'=>[],'subject'=>$subject];
    }
}
