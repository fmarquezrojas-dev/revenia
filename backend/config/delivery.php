<?php

return ['queue_connection' => env('DELIVERY_QUEUE_CONNECTION', 'redis'), 'demo_recipient' => env('DOCUMENT_DEMO_RECIPIENT'), 'template_version' => '1', 'max_emails_per_day' => 3, 'email_cooldown_seconds' => 60];
