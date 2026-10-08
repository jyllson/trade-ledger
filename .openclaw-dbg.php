<?php
config(['etoro.api_key' => 'abc/def+ghi=é-key']);
$s = app(App\Application\DemoCopy\DemoCopyResponseSanitizer::class);
$m = new ReflectionMethod($s, 'secretVariants');
var_dump($m->invoke($s));
var_dump($s->reason('Bad key abc\/def+ghi=é-key sent'));
