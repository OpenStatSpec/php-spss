# R/haven interoperability gate

Run the standalone release check from the repository root:

```bash
php tools/interop/haven-roundtrip.php
```

It requires `Rscript` and the R package `haven`. The check uses a private temporary directory and removes it when finished; it does not keep binary fixtures in the repository.

The gate verifies:

- PHP Writer output read by haven for byte-compressed SAV and ZLIB-compressed ZSAV, including short strings and a declared 700-byte very-long string;
- a PHP-generated multi-block ZSAV whose decompressed bytecode crosses the `0x3ff000` block boundary;
- haven output read by PHP for byte-compressed SAV and ZSAV, including per-row NOP-padded opcode clusters;
- both bulk and case-iterator PHP read paths for haven output.

Exit status `0` means pass, `1` means an interoperability failure, and `77` means the R/haven prerequisite is unavailable. The gate is blocking in CI; `composer test:interop` runs it locally.
