<?php

declare (strict_types=1);
namespace App\BoxGeneric;

use App\BoxGeneric\Containers\Box;
use App\BoxGeneric\Models\Metal;
use App\BoxGeneric\Models\Plastic;
$plasticBox = new \XPHP\Generated\App\BoxGeneric\Containers\Box\T_a999dea8b8a7aff22813b285998be1bb329f1e3e7679044afc7c2707c6585b7d();
$plasticBox->set(new Plastic('red'));
$metalBox = new \XPHP\Generated\App\BoxGeneric\Containers\Box\T_df701a86773b4f64e86b4cb6a70b4404206bb485e164160034b46280927f23fb();
$metalBox->set(new Metal(7));
$secondPlasticBox = new \XPHP\Generated\App\BoxGeneric\Containers\Box\T_a999dea8b8a7aff22813b285998be1bb329f1e3e7679044afc7c2707c6585b7d();
