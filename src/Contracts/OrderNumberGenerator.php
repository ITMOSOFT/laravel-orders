<?php

namespace ItmoSoft\Orders\Contracts;

interface OrderNumberGenerator
{
    public function generate(): string;
}
