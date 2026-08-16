<?php

namespace GlpiPlugin\Assetsign\Provider;

abstract class AbstractProvider implements SignatureProviderInterface
{
   public function __construct(
        protected readonly array $providerConfig,
        protected readonly int $linkValidityDays,
    ) {
   }

   public function managesReminders(): bool {
       return false;
   }
}
