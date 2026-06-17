<?php

declare (strict_types=1);
namespace App\PipeOperator;

// Pure pass-through: the PHP 8.5 pipe operator must survive transpilation
// untouched -- xphp owns generic syntax only, everything else is plain PHP.
$slug = $title |> trim(...) |> strtolower(...);
// Pipe coexists with a generic specialization: the turbofish call site gets
// rewritten to the monomorphized FQN while the |> tokens beside it are left
// intact (proves the generic byte-offset rewriting doesn't disturb them).
$box = new \XPHP\Generated\App\PipeOperator\Box\T_473287f8298dba7163a897908958f7c0eae733e25d2e027992ea2edc9bed2fa8('  HELLO  ');
$shout = $box->value |> trim(...) |> strtoupper(...);
