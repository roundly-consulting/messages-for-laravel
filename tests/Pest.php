<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Testing\MessageExpectations;
use RoundlyConsulting\Messages\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

MessageExpectations::register();
