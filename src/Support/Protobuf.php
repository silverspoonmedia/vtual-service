<?php

namespace Silverspoonmedia\VtualService\Support;

/**
 * Minimal proto3 wire-format writer.
 *
 * The upstream project depends on `google/protobuf` plus a `protoc` codegen
 * step to build a handful of tiny continuation tokens (community + shorts
 * search). Those messages only use two wire types (varint and
 * length-delimited), so we encode them directly here. This removes the native
 * extension / codegen requirement and keeps the package install pure-PHP.
 *
 * Prototypes ported from proto/prototypes/*.proto.
 */
class Protobuf
{
    /** Accumulated wire bytes, keyed nothing — appended in field order. */
    private string $buffer = '';

    public function toString(): string
    {
        return $this->buffer;
    }

    /** proto3 varint field (wire type 0). */
    public function varint(int $fieldNumber, int $value): self
    {
        $this->buffer .= self::encodeKey($fieldNumber, 0);
        $this->buffer .= self::encodeVarint($value);

        return $this;
    }

    /** proto3 length-delimited field (wire type 2): string or nested message. */
    public function bytes(int $fieldNumber, string $value): self
    {
        $this->buffer .= self::encodeKey($fieldNumber, 2);
        $this->buffer .= self::encodeVarint(strlen($value));
        $this->buffer .= $value;

        return $this;
    }

    /** Nested message field. */
    public function message(int $fieldNumber, self $message): self
    {
        return $this->bytes($fieldNumber, $message->toString());
    }

    private static function encodeKey(int $fieldNumber, int $wireType): string
    {
        return self::encodeVarint(($fieldNumber << 3) | $wireType);
    }

    private static function encodeVarint(int $value): string
    {
        $out = '';
        // Treat as unsigned 64-bit.
        $value &= 0xFFFFFFFFFFFFFFFF;
        do {
            $byte = $value & 0x7F;
            $value = ($value >> 7) & 0x01FFFFFFFFFFFFFF;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($value !== 0);

        return $out;
    }

    /**
     * Build the `community` browse continuation params.
     *
     * Upstream prototypes: Browse { endpoint=2, subBrowse=25 },
     *                      SubBrowse { postId=22 }.
     * Used by community.php to fetch a single post by id.
     */
    public static function communityParams(string $postId): string
    {
        $subBrowse = (new self)->bytes(22, $postId);
        $browse = (new self)
            ->bytes(2, 'community')
            ->message(25, $subBrowse);

        return base64_encode($browse->toString());
    }

    /**
     * Build the shorts search continuation token.
     *
     * Upstream prototypes: BrowseShorts and Sub*BrowseShorts.
     * Used by search.php when `type=short`.
     */
    public static function shortsSearchContinuation(string $query): string
    {
        // Sub1 -> Sub2(18) -> Sub3(7: Sub4_7(12=26), 9: Sub4_9{})
        $sub4_7 = (new self)->varint(12, 26);
        $sub4_9 = new self;
        $sub3 = (new self)
            ->message(7, $sub4_7)
            ->message(9, $sub4_9);
        $sub2 = (new self)->message(18, $sub3);
        $sub1 = (new self)->message(2, $sub2);

        $sub0 = (new self)
            ->bytes(2, $query)
            ->bytes(3, base64_encode($sub1->toString()));

        $browseShorts = (new self)
            ->message(2, $sub0)
            ->varint(3, 52047873)
            ->bytes(4, 'search-page');

        return Parsers::base64UrlEncode($browseShorts->toString());
    }
}
