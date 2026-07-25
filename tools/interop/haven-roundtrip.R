#!/usr/bin/env Rscript

args <- commandArgs(trailingOnly = TRUE)

skip <- function(message) {
  cat("SKIP:", message, "\n", file = stderr())
  quit(save = "no", status = 77L)
}

assert_true <- function(condition, message) {
  if (!isTRUE(condition)) {
    stop(message, call. = FALSE)
  }
}

if (!requireNamespace("haven", quietly = TRUE)) {
  skip("R package 'haven' is not installed.")
}

if (length(args) == 1L && identical(args[[1]], "--check")) {
  cat(
    "R ", as.character(getRversion()),
    "; haven ", as.character(utils::packageVersion("haven")),
    "\n",
    sep = ""
  )
  quit(save = "no", status = 0L)
}

if (length(args) != 2L || !identical(args[[1]], "--verify-php")) {
  cat(
    "Usage: Rscript tools/interop/haven-roundtrip.R --check\n",
    "   or: Rscript tools/interop/haven-roundtrip.R --verify-php <temp-directory>\n",
    file = stderr()
  )
  quit(save = "no", status = 2L)
}

work_dir <- normalizePath(args[[2]], mustWork = TRUE)
long_value <- paste(rep("\u00d5", 320L), collapse = "")

verify_php_file <- function(filename) {
  path <- file.path(work_dir, filename)
  data <- haven::read_sav(path, user_na = TRUE)

  assert_true(nrow(data) == 3L, paste(filename, "must contain 3 rows."))
  assert_true(ncol(data) == 3L, paste(filename, "must contain 3 columns."))
  assert_true(
    identical(names(data), c("number", "short_text", "long_text")),
    paste(filename, "contains unexpected variable names.")
  )
  assert_true(
    identical(as.numeric(data[[1]]), c(1, 2.5, 999)),
    paste(filename, "contains unexpected numeric values.")
  )
  assert_true(
    identical(as.character(data[[2]]), c("abc", "", "xyz")),
    paste(filename, "contains unexpected short-string values.")
  )
  assert_true(
    identical(as.character(data[[3]]), c(long_value, "", "tail")),
    paste(filename, "contains unexpected very-long-string values.")
  )
  assert_true(
    identical(attr(data[[3]], "format.spss", exact = TRUE), "A700"),
    paste(filename, "does not expose the expected A700 format.")
  )
}

verify_php_file("php-byte.sav")
verify_php_file("php-zlib.zsav")

multi <- haven::read_sav(file.path(work_dir, "php-multiblock.zsav"))
assert_true(nrow(multi) == 470000L, "Multi-block ZSAV must contain 470000 rows.")
assert_true(ncol(multi) == 1L, "Multi-block ZSAV must contain one column.")
assert_true(identical(as.numeric(multi[[1]][1]), 0.5), "Unexpected first multi-block value.")
assert_true(
  identical(as.numeric(multi[[1]][470000]), 469999.5),
  "Unexpected last multi-block value."
)

haven_data <- data.frame(
  number = c(1, 2.5, 999),
  text = c("abc", "", "xyz"),
  stringsAsFactors = FALSE
)
haven::write_sav(haven_data, file.path(work_dir, "haven-byte.sav"), compress = "byte")
haven::write_sav(haven_data, file.path(work_dir, "haven-zlib.zsav"), compress = "zsav")

cat("R/haven verified PHP SAV, ZSAV, and multi-block ZSAV; reverse fixtures written.\n")
