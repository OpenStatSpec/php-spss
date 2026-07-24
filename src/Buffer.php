<?php

namespace SPSS;

class Buffer
{
    public mixed $context = null;

    public bool $isBigEndian = false;

    public ?string $charset = null;

    /**
     * @var resource
     */
    private $_stream;

    private int $_position = 0;

    /**
     * Buffer constructor.
     *
     * @param resource $stream  stream resource to wrap
     * @param array{context?: mixed, memory?: bool} $options associative array of options
     */
    private function __construct($stream, array $options = [])
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource.');
        }

        $this->_stream = $stream;

        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
    }

    /**
     * Create a new stream based on the input type.
     *
     * @param resource|string|\Stringable          $resource Entity body data
     * @param array{context?: mixed, memory?: bool} $options  Additional options
     */
    public static function factory($resource = '', array $options = []): self
    {
        if (\is_string($resource)) {
            $stream = isset($options['memory'])
                ? fopen('php://memory', 'rb+')
                : fopen('php://temp', 'rb+');
            if (false === $stream) {
                throw new Exception('Unable to open buffer stream.');
            }

            if ('' !== $resource) {
                if (false === fwrite($stream, $resource)) {
                    throw new Exception('Unable to write initial buffer contents.');
                }

                if (0 !== fseek($stream, 0)) {
                    throw new Exception('Unable to rewind initial buffer contents.');
                }
            }

            return new self($stream, $options);
        }

        if (\is_resource($resource)) {
            return new self($resource, $options);
        }

        if ($resource instanceof \Stringable) {
            return self::factory((string) $resource, $options);
        }

        throw new \InvalidArgumentException(sprintf('Invalid resource type: %s.', \gettype($resource)));
    }

    /**
     *
     * @throws Exception
     */
    public function allocate(int $length, bool $skip = true): self
    {
        $stream = fopen('php://memory', 'rb+');
        if (false === $stream) {
            throw new Exception('Unable to open allocation stream.');
        }

        if (false === stream_copy_to_stream($this->_stream, $stream, $length)) {
            throw new Exception('Buffer allocation failed.');
        }

        if ($skip) {
            $this->skip($length);
        }

        $buffer = new self($stream);
        $buffer->charset = $this->charset;
        $buffer->isBigEndian = $this->isBigEndian;

        return $buffer;
    }

    public function skip(int $length): void
    {
        $this->_position += $length;
    }

    /**
     * @param string $file Path to file
     */
    public function saveToFile(string $file): int|false
    {
        if (!rewind($this->_stream)) {
            return false;
        }

        return file_put_contents($file, $this->_stream);
    }

    /**
     * @param resource $resource
     */
    public function writeStream($resource, ?int $maxlength = null): int|false
    {
        if (!\is_resource($resource)) {
            throw new \InvalidArgumentException('Invalid resource type.');
        }

        if (null !== $maxlength) {
            $length = stream_copy_to_stream($resource, $this->_stream, $maxlength);
        } else {
            $length = stream_copy_to_stream($resource, $this->_stream);
        }

        if (false !== $length) {
            $this->_position += $length;
        }

        return $length;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->_stream;
    }

    public function readString(int $length, int $round = 0, ?string $charset = null): string|false
    {
        if ($bytes = $this->readBytes($length)) {
            if ($round !== 0) {
                $this->skip(Utils::roundUp($length, $round) - $length);
            }

            $str = Utils::bytesToString($bytes);

            $charsetFrom = $this->charset ?? mb_internal_encoding();
            $charsetTo = $charset ?? mb_internal_encoding();
            if (strtolower($charsetFrom) !== strtolower($charsetTo)) {
                return mb_convert_encoding($str, $charsetTo, $charsetFrom);
            }

            return $str;
        }

        return false;
    }

    /**
     * @return list<int>|false
     */
    public function readBytes(int $length): array|false
    {
        $bytes = $this->read($length);
        if (false !== $bytes) {
            $unpacked = unpack('C*', $bytes);

            return false === $unpacked ? false : array_values($unpacked);
        }

        return false;
    }

    /**
     * @param int $length
     */
    public function read(?int $length = null): string|false
    {
        $bytes = stream_get_contents($this->_stream, $length, $this->_position);
        if (false !== $bytes) {
            $this->_position += $length ?? 0;
        }

        return $bytes;
    }

    /**
     * @param $data
     *
     */
    public function writeString(string|int|float|null $data, int|string $length = '*', ?string $charset = null): int|false
    {
        $charsetTo = $this->charset ?? mb_internal_encoding();
        $charsetFrom = $charset ?? mb_internal_encoding();
        $data = (string) $data;
        if (strtolower($charsetFrom) !== strtolower($charsetTo)) {
            $data = mb_convert_encoding($data, $charsetTo, $charsetFrom);
        }

        //file_put_contents("/var/encuestas/test.txt", "To: " . $charsetTo . " FROM:" . $charsetFrom . "\n", FILE_APPEND | LOCK_EX);
        return $this->write(pack('A' . $length, $data));
    }

    public function write(string $data, ?int $length = null): int|false
    {
        $written = null !== $length
            ? fwrite($this->_stream, $data, $length)
            : fwrite($this->_stream, $data);
        if (false !== $written) {
            $this->_position += $written;
        }

        return $written;
    }

    /**
     * @return float
     */
    public function readDouble(): float|false
    {
        $value = $this->readNumeric(8, 'd');

        return false === $value ? false : (float) $value;
    }

    public function writeDouble(float|int|string $data): int|false
    {
        if (!is_numeric($data)) {
            throw new \InvalidArgumentException('Double value must be numeric.');
        }

        return $this->writeNumeric((float) $data, 'd', 8);
    }

    /**
     * @param $data
     * @param $format
     */
    public function writeNumeric(int|float $data, string $format, ?int $length = null): int|false
    {
        return $this->write(pack($format, $data), $length);
    }

    public function readFloat(): float|false
    {
        $value = $this->readNumeric(4, 'f');

        return false === $value ? false : (float) $value;
    }

    /**
     * @param $data
     */
    public function writeFloat(float $data): int|false
    {
        return $this->writeNumeric($data, 'f', 4);
    }

    /**
     * @return int
     */
    public function readInt(): int|false
    {
        $value = $this->readNumeric(4, 'i');

        return false === $value ? false : (int) $value;
    }

    /**
     * @param $data
     */
    public function writeInt(int $data): int|false
    {
        return $this->writeNumeric($data, 'i', 4);
    }

    public function readInt64(): int|false
    {
        $value = $this->readNumeric(8, 'q');

        return false === $value ? false : (int) $value;
    }

    public function writeInt64(int $data): int|false
    {
        $bytes = pack('q', $data);
        if ($this->isBigEndian) {
            $bytes = strrev($bytes);
        }

        return $this->write($bytes, 8);
    }

    public function readShort(): int|false
    {
        $value = $this->readNumeric(2, 'v');

        return false === $value ? false : (int) $value;
    }

    /**
     * @param $data
     */
    public function writeShort(int $data): int|false
    {
        return $this->writeNumeric($data, 'v', 2);
    }

    /**
     * @param $length
     */
    public function writeNull(int $length): int|false
    {
        return $this->write(pack('x' . $length));
    }

    public function position(): int
    {
        // return ftell($this->_stream);
        return $this->_position;
    }

    public function seek(int $offset, int $whence = SEEK_SET): int
    {
        $result = fseek($this->_stream, $offset, $whence);
        if (0 === $result) {
            $this->_position = $offset;
        }

        return $result;
    }

    public function rewind(): void
    {
        if (rewind($this->_stream)) {
            $this->_position = 0;
        }
    }

    public function truncate(): void
    {
        if (!ftruncate($this->_stream, 0)) {
            throw new Exception('Unable to truncate buffer stream.');
        }

        $this->_position = 0;
    }

    public function close(): bool
    {
        return fclose($this->_stream);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetaData(): array
    {
        return stream_get_meta_data($this->_stream);
    }

    private function readNumeric(int $length, string $format): int|float|false
    {
        $bytes = $this->read($length);
        if (false !== $bytes && '' !== $bytes) {
            if ($this->isBigEndian) {
                $bytes = strrev($bytes);
            }

            $data = unpack($format, $bytes);
            if (false !== $data && isset($data[1]) && (\is_int($data[1]) || \is_float($data[1]))) {
                return $data[1];
            }
        }

        return false;
    }
}
