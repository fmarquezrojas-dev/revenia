<?php

return ['demo_controls' => (bool) env('SIMULATION_DEMO_CONTROLS', false), 'queue_connection' => env('EMISSION_QUEUE_CONNECTION', 'redis')];
