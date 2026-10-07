<?php

namespace App\Attendance;

use RuntimeException;

/** No saved template fits the file: create one from it first. */
class NoTemplateMatches extends RuntimeException {}
