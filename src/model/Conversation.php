<?php

namespace think\agent\model;

use think\Model;

abstract class Conversation extends Model
{
    abstract public function messages();
}