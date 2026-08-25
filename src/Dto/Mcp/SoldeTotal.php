<?php

namespace App\Dto\Mcp;

class SoldeTotal
{
    public function __construct(
        public float $total,
        public int $nombre,
    ) {}
}
