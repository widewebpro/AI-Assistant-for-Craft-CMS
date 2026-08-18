<?php

namespace widewebpro\aiassistant\events;

use widewebpro\aiassistant\tools\BaseTool;
use yii\base\Event;

class RegisterToolsEvent extends Event
{
    /** @var BaseTool[] Registered tools keyed by name; append/replace/unset entries. */
    public array $tools = [];
}
