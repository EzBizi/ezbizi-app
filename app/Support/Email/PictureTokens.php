<?php

namespace App\Support\Email;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The original image URL never crosses the wire.
 *
 * A remote `<img src>` is a host the sender chose. Publishing it would let
 * the client fetch what {@see RemoteHostGuard} exists to refuse, and would
 * complete a tracking pixel from the reader's browser. So the sanitiser
 * keeps the URL on the Block in PHP, and this class is what the controller
 * runs before serialising: http(s) and cid become an encrypted token, a
 * `data:` URI (already the bytes) is left alone.
 */
final class PictureTokens
{
    /** @param  Block[]  $blocks
     *  @return Block[] */
    public function seal(array $blocks): array
    {
        return array_map(fn (Block $block) => $this->sealOne($block), $blocks);
    }

    public function sealOne(Block $block): Block
    {
        if ($block->kind !== BlockKind::Image || $block->src === null) {
            return $block;
        }

        if (str_starts_with($block->src, 'data:')) {
            return $block;
        }

        return $block->withSrc(Crypt::encryptString($block->src));
    }

    public function reveal(string $token): string
    {
        try {
            $src = Crypt::decryptString($token);
        } catch (DecryptException) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        if ($src === '' || str_starts_with($src, 'data:')) {
            throw new PictureUnavailable('That picture could not be loaded.');
        }

        return $src;
    }
}
