<?php

namespace SPSS\Sav\Record;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record;
use SPSS\Utils;

class Data extends Record
{
    public const TYPE = 999;

    /** No-operation. This is simply ignored. */
    public const OPCODE_NOP = 0;

    /** End-of-file. */
    public const OPCODE_EOF = 252;

    /** Verbatim raw data. Read an 8-byte segment of raw data. */
    public const OPCODE_RAW_DATA = 253;

    /** Compressed whitespaces. Expand to an 8-byte segment of whitespaces. */
    public const OPCODE_WHITESPACES = 254;

    /** Compressed sysmiss value. Expand to an 8-byte segment of SYSMISS value. */
    public const OPCODE_SYSMISS = 255;

    private const int ZLIB_BLOCK_SIZE = 0x3ff000;

    /**
     * @var array<int, array<int, mixed>> [case_index][var_index]
     */
    public $matrix = [];

    /**
     * @var array<int, mixed> [var_index]
     */
    public $row = [];

    /**
     * @var list<int> Latest opcodes data
     */
    protected $opcodes = [];

    /**
     * @var int Current opcode index
     */
    protected $opcodeIndex = 0;

    /**
     * @var int Position where the data start
     */
    protected $startData = -1;

    /**
     * @var Buffer|null Temporary buffer
     */
    protected $dataBuffer;

    /**
     * @var Buffer|null Decompressed bytecode buffer for ZSAV iteration
     */
    protected $zlibBuffer;

    public function readCase(Buffer $buffer, int $case): void
    {
        /* check if this is the first time */
        if ($this->startData === -1) {
            $this->opcodeIndex = 8;
            $this->opcodes     = [];

            $this->startData = $buffer->position();
            if (0 !== $buffer->readInt()) {
                throw new \InvalidArgumentException('Error reading data record. Non-zero value found.');
            }

            if (!isset($buffer->context->variables)) {
                throw new \InvalidArgumentException('Variables required');
            }

            if (!isset($buffer->context->header)) {
                throw new \InvalidArgumentException('Header required');
            }

            if (!isset($buffer->context->info)) {
                throw new \InvalidArgumentException('Info required');
            }
        }

        $compressed = 0 !== $buffer->context->header->compression;
        $bias       = $buffer->context->header->bias;
        $casesCount = $buffer->context->header->casesCount;

        /** @var list<Variable> $variables */
        $variables = $buffer->context->variables;

        /** @var array<int, Record\Info> $info */
        $info = $buffer->context->info;

        $veryLongStrings = [];
        if (isset($info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        }

        $machineFloatingPoint = $info[Record\Info\MachineFloatingPoint::SUBTYPE] ?? null;
        if ($machineFloatingPoint instanceof Record\Info\MachineFloatingPoint) {
            $sysmis = $machineFloatingPoint->sysmis;
        } else {
            $sysmis = NAN;
        }

        $caseBuffer = $buffer;
        if (2 === $buffer->context->header->compression) {
            $caseBuffer = $this->zlibBuffer ??= $this->readZlibData($buffer, $bias);
        }

        if (($case >= 0) && ($case < $casesCount)) {
            $this->row = $this->readCaseData(
                $caseBuffer,
                $compressed,
                $bias,
                $variables,
                $veryLongStrings,
                $sysmis,
            );
        }
    }

    public function read(Buffer $buffer): void
    {
        if ($this->startData === -1) {
            $this->startData = $buffer->position();
        }

        if ($buffer->readInt() !== 0) {
            throw new \InvalidArgumentException('Error reading data record. Non-zero value found.');
        }

        if (!isset($buffer->context->variables)) {
            throw new \InvalidArgumentException('Variables required');
        }

        if (!isset($buffer->context->header)) {
            throw new \InvalidArgumentException('Header required');
        }

        if (!isset($buffer->context->info)) {
            throw new \InvalidArgumentException('Info required');
        }

        $compressed = $buffer->context->header->compression;
        $bias       = $buffer->context->header->bias;
        $casesCount = $buffer->context->header->casesCount;

        /** @var Variable[] $variables */
        $variables = $buffer->context->variables;

        /** @var Record\Info[] $info */
        $info = $buffer->context->info;

        $veryLongStrings = [];
        if (isset($info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        }

        $machineFloatingPoint = $info[Record\Info\MachineFloatingPoint::SUBTYPE] ?? null;
        if ($machineFloatingPoint instanceof Record\Info\MachineFloatingPoint) {
            $sysmis = $machineFloatingPoint->sysmis;
        } else {
            $sysmis = NAN;
        }

        $dataBuffer = 2 === $compressed
            ? $this->readZlibData($buffer, $bias)
            : $buffer;

        $this->opcodeIndex = 8;

        for ($case = 0; $case < $casesCount; $case++) {
            $this->matrix[$case] = $this->readCaseData(
                $dataBuffer,
                $compressed,
                $bias,
                $variables,
                $veryLongStrings,
                $sysmis,
            );
        }
    }

    /** @param array<int, mixed> $row */
    public function writeCase(Buffer $buffer, array $row): void
    {
        if (!isset($buffer->context->variables)) {
            throw new \InvalidArgumentException('Variables required');
        }

        if (!isset($buffer->context->header)) {
            throw new \InvalidArgumentException('Header required');
        }

        if (!isset($buffer->context->info)) {
            throw new \InvalidArgumentException('Info required');
        }

        $compressed = $buffer->context->header->compression;
        $bias       = $buffer->context->header->bias;
        // $casesCount = $buffer->context->header->casesCount;

        if (2 === $compressed) {
            throw new \LogicException('Incremental ZSAV writing is not supported.');
        }

        /** @var Variable[] $variables */
        $variables = $buffer->context->variables;

        /** @var Record\Info[] $info */
        $info = $buffer->context->info;

        $veryLongStrings = [];
        if (isset($info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        }

        $machineFloatingPoint = $info[Record\Info\MachineFloatingPoint::SUBTYPE] ?? null;
        if ($machineFloatingPoint instanceof Record\Info\MachineFloatingPoint) {
            $sysmis = $machineFloatingPoint->sysmis;
        } else {
            $sysmis = NAN;
        }

        if ($this->startData === -1) {
            $buffer->writeInt(self::TYPE);
            $this->startData = $buffer->position();
            $buffer->writeInt(0);

            if ($compressed) {
                $this->dataBuffer = Buffer::factory('', ['memory' => true]);
            }
        }

        $this->writeCaseData($buffer, $row, $compressed, $bias, $variables, $veryLongStrings, $sysmis);
        if ($compressed) {
            $this->writeOpcode($buffer, self::OPCODE_EOF);
        }
    }

    public function write(Buffer $buffer): void
    {
        if (!isset($buffer->context->variables)) {
            throw new \InvalidArgumentException('Variables required');
        }

        if (!isset($buffer->context->header)) {
            throw new \InvalidArgumentException('Header required');
        }

        if (!isset($buffer->context->info)) {
            throw new \InvalidArgumentException('Info required');
        }

        $compressed = $buffer->context->header->compression;
        $bias       = $buffer->context->header->bias;
        $casesCount = $buffer->context->header->casesCount;

        /** @var Variable[] $variables */
        $variables = $buffer->context->variables;

        /** @var Record\Info[] $info */
        $info = $buffer->context->info;

        $veryLongStrings = [];
        if (isset($info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        }

        $machineFloatingPoint = $info[Record\Info\MachineFloatingPoint::SUBTYPE] ?? null;
        if ($machineFloatingPoint instanceof Record\Info\MachineFloatingPoint) {
            $sysmis = $machineFloatingPoint->sysmis;
        } else {
            $sysmis = NAN;
        }

        $buffer->writeInt(self::TYPE);
        $this->startData = $buffer->position();
        $buffer->writeInt(0);

        $outputBuffer = $buffer;
        if (2 === $compressed) {
            $outputBuffer = Buffer::factory('', ['memory' => true]);
            $outputBuffer->charset = $buffer->charset;
            $outputBuffer->isBigEndian = $buffer->isBigEndian;
        }

        if ($compressed) {
            $this->dataBuffer = Buffer::factory('', ['memory' => true]);
        }

        if (\count($this->matrix) > 0) {
            for ($case = 0; $case < $casesCount; $case++) {
                $row = $this->matrix[$case];
                $this->writeCaseData(
                    $outputBuffer,
                    $row,
                    $compressed,
                    $bias,
                    $variables,
                    $veryLongStrings,
                    $sysmis,
                );
            }
        }

        if ($compressed) {
            $this->writeOpcode($outputBuffer, self::OPCODE_EOF);
        }

        if (2 === $compressed) {
            $this->writeZlibData($buffer, $outputBuffer, $bias);
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        return $this->matrix;
    }

    /**
     * @return array<int, mixed>
     */
    public function getRow(): array
    {
        return $this->row;
    }

    /**
     * @return true|false
     */
    public function close()
    {
        if ($this->dataBuffer !== null) {
            return $this->dataBuffer->close();
        }

        return false;
    }

    private function readZlibData(Buffer $buffer, float $bias): Buffer
    {
        if (!\function_exists('gzuncompress')) {
            throw new Exception('Reading ZSAV files requires the zlib extension.');
        }

        $actualHeaderOffset = $buffer->position();
        $headerOffset = $buffer->readInt64();
        $trailerOffset = $buffer->readInt64();
        $trailerLength = $buffer->readInt64();
        if (in_array(false, [$headerOffset, $trailerOffset, $trailerLength], true)) {
            throw new Exception('Invalid ZSAV data: truncated ZLIB header.');
        }

        $fileSize = $this->streamSize($buffer);
        if ($headerOffset !== $actualHeaderOffset) {
            throw new Exception('Invalid ZSAV data: incorrect ZLIB header offset.');
        }

        if ($trailerOffset < $headerOffset + 24
            || $trailerLength < 48
            || 0 !== ($trailerLength - 24) % 24
            || $trailerOffset + $trailerLength !== $fileSize
        ) {
            throw new Exception('Invalid ZSAV data: incorrect ZLIB trailer offsets or length.');
        }

        if (0 !== $buffer->seek($trailerOffset)) {
            throw new Exception('Invalid ZSAV data: unable to seek to the ZLIB trailer.');
        }

        $integerBias = $buffer->readInt64();
        $zero = $buffer->readInt64();
        $blockSize = $buffer->readInt();
        $blockCount = $buffer->readInt();
        if (in_array(false, [$integerBias, $zero, $blockSize, $blockCount], true)) {
            throw new Exception('Invalid ZSAV data: truncated ZLIB trailer.');
        }

        $expectedBlockCount = intdiv($trailerLength - 24, 24);
        if ($integerBias !== -(int) $bias
            || 0 !== $zero
            || self::ZLIB_BLOCK_SIZE !== $blockSize
            || $blockCount < 1
            || $blockCount !== $expectedBlockCount
        ) {
            throw new Exception('Invalid ZSAV data: inconsistent ZLIB trailer.');
        }

        /** @var list<array{uncompressedOffset: int, compressedOffset: int, uncompressedSize: int, compressedSize: int}> $descriptors */
        $descriptors = [];
        $expectedUncompressedOffset = $headerOffset;
        $expectedCompressedOffset = $headerOffset + 24;

        for ($index = 0; $index < $blockCount; $index++) {
            $uncompressedOffset = $buffer->readInt64();
            $compressedOffset = $buffer->readInt64();
            $uncompressedSize = $buffer->readInt();
            $compressedSize = $buffer->readInt();
            if (in_array(false, [$uncompressedOffset, $compressedOffset, $uncompressedSize, $compressedSize], true)
            ) {
                throw new Exception('Invalid ZSAV data: truncated ZLIB block descriptor.');
            }

            $isLastBlock = $index === $blockCount - 1;
            if ($uncompressedOffset !== $expectedUncompressedOffset
                || $compressedOffset !== $expectedCompressedOffset
                || $uncompressedSize < 1
                || $uncompressedSize > $blockSize
                || (!$isLastBlock && $uncompressedSize !== $blockSize)
                || $compressedSize < 1
                || $compressedOffset + $compressedSize > $trailerOffset
            ) {
                throw new Exception('Invalid ZSAV data: inconsistent ZLIB block descriptor.');
            }

            $descriptors[] = [
                'uncompressedOffset' => $uncompressedOffset,
                'compressedOffset' => $compressedOffset,
                'uncompressedSize' => $uncompressedSize,
                'compressedSize' => $compressedSize,
            ];
            $expectedUncompressedOffset += $uncompressedSize;
            $expectedCompressedOffset += $compressedSize;
        }

        if ($expectedCompressedOffset !== $trailerOffset) {
            throw new Exception('Invalid ZSAV data: compressed blocks do not end at the trailer.');
        }

        $bytecode = '';
        foreach ($descriptors as $descriptor) {
            if (0 !== $buffer->seek($descriptor['compressedOffset'])) {
                throw new Exception('Invalid ZSAV data: unable to seek to a compressed block.');
            }

            $compressedData = $buffer->read($descriptor['compressedSize']);
            if (false === $compressedData || \strlen($compressedData) !== $descriptor['compressedSize']) {
                throw new Exception('Invalid ZSAV data: truncated compressed block.');
            }

            $uncompressedData = @gzuncompress($compressedData, $descriptor['uncompressedSize']);
            if (false === $uncompressedData) {
                throw new Exception('Invalid ZSAV data: corrupt compressed block or checksum.');
            }

            if (\strlen($uncompressedData) !== $descriptor['uncompressedSize']) {
                throw new Exception('Invalid ZSAV data: decompressed block size mismatch.');
            }

            $bytecode .= $uncompressedData;
        }

        $buffer->seek($fileSize);
        $bytecodeBuffer = Buffer::factory($bytecode, ['memory' => true]);
        $bytecodeBuffer->charset = $buffer->charset;
        $bytecodeBuffer->isBigEndian = $buffer->isBigEndian;
        $bytecodeBuffer->context = $buffer->context;

        return $bytecodeBuffer;
    }

    private function writeZlibData(Buffer $buffer, Buffer $bytecodeBuffer, float $bias): void
    {
        if (!\function_exists('gzcompress')) {
            throw new Exception('Writing ZSAV files requires the zlib extension.');
        }

        $integerBias = (int) $bias;
        if ((float) $integerBias !== $bias) {
            throw new Exception('ZSAV compression requires an integer compression bias.');
        }

        $stream = $bytecodeBuffer->getStream();
        $streamInfo = fstat($stream);
        if (false === $streamInfo) {
            throw new Exception('Unable to determine ZSAV bytecode stream size.');
        }

        $bytecodeSize = $streamInfo['size'];
        $bytecode = stream_get_contents($stream, $bytecodeSize, 0);
        if (false === $bytecode || \strlen($bytecode) !== $bytecodeSize) {
            throw new Exception('Unable to read ZSAV bytecode stream.');
        }

        $chunks = str_split($bytecode, self::ZLIB_BLOCK_SIZE);
        if ([] === $chunks) {
            $chunks = [''];
        }

        /** @var list<array{compressed: string, uncompressedSize: int, compressedSize: int}> $blocks */
        $blocks = [];
        foreach ($chunks as $chunk) {
            $compressed = gzcompress($chunk);
            if (false === $compressed) {
                throw new Exception('Unable to compress a ZSAV data block.');
            }

            $blocks[] = [
                'compressed' => $compressed,
                'uncompressedSize' => \strlen($chunk),
                'compressedSize' => \strlen($compressed),
            ];
        }

        $headerOffset = $buffer->position();
        $trailerOffset = $headerOffset + 24;
        foreach ($blocks as $block) {
            $trailerOffset += $block['compressedSize'];
        }

        $trailerLength = 24 + 24 * \count($blocks);
        $buffer->writeInt64($headerOffset);
        $buffer->writeInt64($trailerOffset);
        $buffer->writeInt64($trailerLength);

        foreach ($blocks as $block) {
            $buffer->write($block['compressed']);
        }

        $buffer->writeInt64(-$integerBias);
        $buffer->writeInt64(0);
        $buffer->writeInt(self::ZLIB_BLOCK_SIZE);
        $buffer->writeInt(\count($blocks));

        $uncompressedOffset = $headerOffset;
        $compressedOffset = $headerOffset + 24;
        foreach ($blocks as $block) {
            $buffer->writeInt64($uncompressedOffset);
            $buffer->writeInt64($compressedOffset);
            $buffer->writeInt($block['uncompressedSize']);
            $buffer->writeInt($block['compressedSize']);

            $uncompressedOffset += $block['uncompressedSize'];
            $compressedOffset += $block['compressedSize'];
        }
    }

    private function streamSize(Buffer $buffer): int
    {
        $streamInfo = fstat($buffer->getStream());
        if (false === $streamInfo) {
            throw new Exception('Unable to determine SPSS stream size.');
        }

        return $streamInfo['size'];
    }


    protected function readOpcode(Buffer $buffer): int
    {
        do {
            if ($this->opcodeIndex >= 8) {
                $this->opcodes     = $buffer->readBytes(8);
                $this->opcodeIndex = 0;
            }

            $opcode = 0xFF & $this->opcodes[$this->opcodeIndex++];
        } while (self::OPCODE_NOP === $opcode);

        return $opcode;
    }

    protected function writeOpcode(Buffer $buffer, int $opcode): void
    {
        if ($this->opcodeIndex >= 8 || self::OPCODE_EOF === $opcode) {
            $pos = $buffer->position();
            foreach ($this->opcodes as $opc) {
                $buffer->write(\chr($opc));
            }

            $padding = max(8 - \count($this->opcodes), 0);
            for ($i = 0; $i < $padding; $i++) {
                $buffer->write(\chr(self::OPCODE_NOP));
            }

            /* @noinspection NotOptimalIfConditionsInspection */
            if (self::OPCODE_EOF === $opcode) {
                $dataPos = $this->dataBuffer->position();
                $this->dataBuffer->rewind();
                $buffer->writeStream($this->dataBuffer->getStream());
                $this->dataBuffer->seek($dataPos);
                $buffer->seek($pos);
            } else {
                $this->opcodes     = [];
                $this->opcodeIndex = 0;
                $this->dataBuffer->rewind();
                $buffer->writeStream($this->dataBuffer->getStream());
                $this->dataBuffer->truncate();
            }
        }

        if (self::OPCODE_EOF !== $opcode) {
            $this->opcodes[$this->opcodeIndex++] = 0xFF & $opcode;
        }
    }

    /**
     * @param  bool  $compressed
     * @param  float  $bias
     * @param  list<Variable>  $variables
     * @param  array<string, int>  $veryLongStrings
     * @param  float  $sysmis
     * @return array<int, mixed>
     */
    protected function readCaseData(Buffer $buffer, $compressed, $bias, array $variables, array $veryLongStrings, $sysmis): array
    {
        $result   = [];
        $varCount = \count($variables);
        $varNum   = 0;

        for ($index = 0; $index < $varCount; $index++) {
            $var = $variables[$index];
            $isNumeric = 0 === $var->width && \SPSS\Sav\Variable::isNumberFormat($var->write[1]);
            $width = 0 !== $var->write[2] ? $var->write[2] : $var->width;

            if ($isNumeric) {
                if (!$compressed) {
                    $result[$varNum] = $buffer->readDouble();
                } else {
                    $opcode = $this->readOpcode($buffer);
                    switch ($opcode) {
                        case self::OPCODE_EOF:
                            throw new Exception('Error reading data: unexpected end of compressed data file (cluster code 252)');
                        case self::OPCODE_RAW_DATA:
                            $result[$varNum] = $buffer->readDouble();
                            break;
                        case self::OPCODE_SYSMISS:
                            $result[$varNum] = $sysmis;
                            break;
                        default:
                            $result[$varNum] = $opcode - $bias;
                            break;
                    }
                }
            } else {
                $width = $veryLongStrings[$var->name] ?? $width;
                $result[$varNum] = '';
                $segmentsCount = Utils::widthToSegments($width);
                $opcode = self::OPCODE_RAW_DATA;
                for ($s = 0; $s < $segmentsCount; $s++) {
                    $segWidth = Utils::segmentAllocWidth($width, $s);
                    for ($i = $segWidth; $i > 0; $i -= 8) {
                        $val = '';
                        if (!$compressed) {
                            $val = $buffer->readString(8);
                        } else {
                            $opcode = $this->readOpcode($buffer);
                            switch ($opcode) {
                                case self::OPCODE_EOF:
                                    throw new Exception('Error reading data: unexpected end of compressed data file (cluster code 252)');
                                case self::OPCODE_RAW_DATA:
                                    $val = $buffer->readString(8);
                                    break;
                                case self::OPCODE_WHITESPACES:
                                    $val = '        ';
                                    break;
                            }
                        }

                        $result[$varNum] .= $val;
                    }

                    $result[$varNum] = rtrim($result[$varNum]);
                }
            }

            $varNum++;
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  bool  $compressed
     * @param  float  $bias
     * @param  list<Variable>  $variables
     * @param  array<string, int>  $veryLongStrings
     * @param  float  $sysmis
     */
    protected function writeCaseData(Buffer $buffer, array $row, $compressed, $bias, array $variables, array $veryLongStrings, $sysmis): void
    {
        foreach ($variables as $index => $var) {
            $value = $row[$index];
            $isNumeric = (($var->width === 0) && \SPSS\Sav\Variable::isNumberFormat($var->write[1]));
            $width = 0 !== $var->write[2] ? $var->write[2] : $var->width;

            if ($isNumeric) {
                if (!$compressed) {
                    $buffer->writeDouble(null === $value ? $sysmis : (float) $value);
                } elseif (null === $value || $value === $sysmis || '' === $value) {
                    $this->writeOpcode($buffer, self::OPCODE_SYSMISS);
                } elseif ($value >= 1 - $bias && $value <= 251 - $bias && $value === (int) $value) {
                    $this->writeOpcode($buffer, (int) ($value + $bias));
                } else {
                    $this->writeOpcode($buffer, self::OPCODE_RAW_DATA);
                    $this->dataBuffer->writeDouble((float) $value);
                }
            } else {
                $offset = 0;
                $width = $veryLongStrings[$var->name] ?? $width;
                $segmentsCount = Utils::widthToSegments($width);

                $charsetFrom = mb_internal_encoding(); //We are assuming the data in the default encode
                $charsetTo = $buffer->charset ?? mb_internal_encoding();
                if (isset($value) && (strtolower($charsetFrom) !== strtolower($charsetTo))) {
                    $value = mb_convert_encoding($value, $charsetTo, $charsetFrom);
                }

                for ($s = 0; $s < $segmentsCount; $s++) {
                    $segWidth = Utils::segmentAllocWidth($width, $s);
                    for ($i = $segWidth; $i > 0; $i -= 8) {
                        $chunkSize = $segWidth === 255 ? min($i, 8) : 8;
                        $val = (isset($value)) ? substr((string) $value, $offset, $chunkSize) : ''; // Read 8 byte segments, don't use mbsubstr here
                        if ($compressed) {
                            if ('' === $val) {
                                $this->writeOpcode($buffer, self::OPCODE_WHITESPACES);
                            } else {
                                $this->writeOpcode($buffer, self::OPCODE_RAW_DATA);
                                $this->dataBuffer->writeString($val, 8);
                            }
                        } else {
                            $buffer->writeString($val, 8, $charsetTo);
                        }

                        $offset += $chunkSize;
                    }
                }
            }
        }
    }
}
