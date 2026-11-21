<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/ReplyPlan.php';
use MantisBat\PrivateMail\ReplyPlan;
$email=['subject'=>'Topic','addresses'=>[
    ['kind'=>'from','address'=>'sender@example.com'], ['kind'=>'reply_to','address'=>'reply@example.com'],
    ['kind'=>'to','address'=>'owner@example.com'], ['kind'=>'to','address'=>'peer@example.com'],
    ['kind'=>'cc','address'=>'PEER@example.com'], ['kind'=>'cc','address'=>'other@example.com'],
]];
$plan=ReplyPlan::create($email,'owner@example.com');
if ($plan['to']!==['reply@example.com','peer@example.com'] || $plan['cc']!==['other@example.com'] || $plan['subject']!=='Re: Topic') throw new RuntimeException('Reply-all plan is incorrect.');
echo "Reply-all planning checks passed.\n";
