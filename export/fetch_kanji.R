# Kanji Adults — pull every study table over the SelfHelp API and write them
# into one Excel workbook, a tab per step of the study.
#
# HOW TO USE
#   1. Fill in the two settings below.
#   2. Run the whole script.
#        In RStudio: open this file and click "Source".
#        Elsewhere:  Rscript fetch_kanji.R
#
#   Any R packages it needs are installed automatically the first time.
#
#   It writes two files next to this script:
#     kanji_export.xlsx    one tab per step of the study, in the order
#                            participants go through them, plus a "Merged"
#                            tab with everything joined on the participant
#                            code, one row per participant
#     kanji_prize_draw.xlsx the prize draw e-mail addresses, kept in their own
#                            file so they cannot be tied to anyone's answers

# --- SETTINGS: fill these in -----------------------------------------------

# Your personal API token, from your SelfHelp profile page.
api_key <- ""

# The SelfHelp address. Must be the server's real address, not localhost,
# unless you are running this on the server itself.
base_url <- "https://tpf-test.humdek.unibe.ch/kanjis"

# ---------------------------------------------------------------------------

# Environment variables win if set, so the script can also be run unattended.
if (nzchar(Sys.getenv("KANJI_API_KEY"))) api_key  <- Sys.getenv("KANJI_API_KEY")
if (nzchar(Sys.getenv("KANJI_API_URL"))) base_url <- Sys.getenv("KANJI_API_URL")

needed  <- c("httr", "jsonlite", "dplyr", "purrr", "tidyr", "writexl")
missing <- needed[!vapply(needed, requireNamespace, logical(1), quietly = TRUE)]

if (length(missing) > 0) {
  message("Installing missing R packages: ", paste(missing, collapse = ", "))
  message("(this only happens the first time, and may take a few minutes)")

  # Install into the personal library so no admin rights are needed. R knows
  # the right per-platform location; it just does not create it on its own,
  # and install.packages() fails if the folder is absent.
  lib <- Sys.getenv("R_LIBS_USER")
  if (!nzchar(lib)) lib <- .libPaths()[1]
  if (!dir.exists(lib)) dir.create(lib, recursive = TRUE, showWarnings = FALSE)
  .libPaths(c(lib, .libPaths()))

  # Use a reliable CRAN mirror. Rscript starts with repos unset.
  repo <- getOption("repos")
  if (is.null(repo) || is.na(repo["CRAN"]) || repo["CRAN"] == "@CRAN@") {
    repo <- c(CRAN = "https://cran.rstudio.com")
  }
  install.packages(missing, lib = lib, repos = repo)

  still <- missing[!vapply(missing, requireNamespace, logical(1), quietly = TRUE)]
  if (length(still) > 0) {
    stop(
      "Could not install: ", paste(still, collapse = ", "), "\n",
      "Try installing them by hand, then run this script again:\n",
      '  install.packages(c("', paste(still, collapse = '","'), '"))',
      call. = FALSE
    )
  }
  message("Packages installed.\n")
}

if (!nzchar(api_key)) {
  stop(
    "No API token set.\n",
    "Open this script and put your token in the api_key line near the top.\n",
    "You can generate one on your SelfHelp profile page.",
    call. = FALSE
  )
}

library(httr)
library(jsonlite)
library(dplyr)
library(purrr)
library(tidyr)
library(writexl)

# Write the CSV next to this script rather than wherever R happens to be.
out_dir <- tryCatch({
  a <- commandArgs(trailingOnly = FALSE)
  f <- sub("^--file=", "", a[grep("^--file=", a)])
  if (length(f) > 0) dirname(normalizePath(f[1])) else dirname(normalizePath(sys.frame(1)$ofile))
}, error = function(e) getwd())
if (is.null(out_dir) || is.na(out_dir) || !nzchar(out_dir)) out_dir <- getwd()


# PrizeDraw is not in this list on purpose. It carries no participant code by
# design, so it cannot be joined, and joining it would tie e-mail addresses to
# task answers. It is exported to its own file at the end of this script.
tables <- c(
  "Kanji_Part1", "Kanji_Demographics",
  "Kanji_Task1", "Kanji_Pause1",
  "Kanji_Task2", "Kanji_Pause2",
  "Kanji_Task3", "Kanji_Pause3",
  "Kanji_Task4", "Kanji_Part2"
)

fetch_table <- function(tbl) {
  r <- GET(
    paste0(base_url, "/api/data/table/", tbl),
    add_headers(`X-API-Key` = api_key)
  )
  stop_for_status(r)
  body <- content(r, as = "text", encoding = "UTF-8")
  parsed <- fromJSON(body, simplifyDataFrame = TRUE)

  if (!identical(parsed$status, 200L) && !identical(parsed$status, 200)) {
    stop(sprintf("%s: API returned %s (%s)", tbl, parsed$status, parsed$message))
  }
  if (length(parsed$response) == 0) return(NULL)

  df <- as_tibble(parsed$response)
  # _json duplicates the flat columns and nests badly in a data frame; _raw_data
  # is the full lab.js event log and is enormous. Drop both for the merge.
  df %>%
    select(-any_of(c("_json", "_raw_data"))) %>%
    mutate(source_table = tbl)
}

message("Fetching ", length(tables), " tables ...")
raw <- set_names(tables) %>% map(function(t) {
  d <- fetch_table(t)
  message(sprintf("  %-20s %s rows", t, if (is.null(d)) 0 else nrow(d)))
  d
})
raw <- compact(raw)

# Keep only completed submissions. `started` and `updated` rows are in-progress
# saves; most carry no code at all and cannot be joined to anything.
finished <- raw %>%
  map(~ filter(.x, triggerType == "finished")) %>%
  map(~ filter(.x, !is.na(extra_param_code), extra_param_code != ""))

# --- Drop the bookkeeping that repeats on every table -----------------------
# Every table arrives with the same block of columns, so the ten-table merge
# carried ten copies of it. Two kinds are dropped:
#
#   derivable  fixed by which table the row came from, so the file name (or
#              the column prefix in the merge) already says it
#   session    the same for a participant no matter which page wrote it, so
#              one copy is enough
#
# Kept per table: record_id, entry_date, triggerType and the per-page timings.
# Those genuinely differ page to page and are how a drop-out is located.
derivable <- c("source_table", "survey_generated_id", "labjs_generated_id",
               "extra_data_block", "id_actionTriggerTypes")

session <- c("id_users", "user_name", "user_code", "extra_data_slice",
             "extra_data_UserLanguage", "labjs_response_id",
             "_meta_pixel_ratio", "_meta_screen_width", "_meta_screen_height",
             "_meta_viewport_width", "_meta_viewport_height", "_meta_user_agent")

# One copy of the session block, first non-empty value per participant. Device
# details can legitimately differ if someone resumes on another device, so say
# so rather than silently keeping whichever page happened to come first.
session_cols <- finished %>%
  map(~ select(.x, any_of(c("extra_param_code", session)))) %>%
  bind_rows() %>%
  mutate(across(everything(), ~ na_if(as.character(.x), ""))) %>%
  group_by(extra_param_code) %>%
  summarise(across(everything(), ~ first(na.omit(.x))), .groups = "drop")

changed <- finished %>%
  map(~ select(.x, any_of(c("extra_param_code", session)))) %>%
  bind_rows() %>%
  mutate(across(-extra_param_code, ~ na_if(as.character(.x), ""))) %>%
  pivot_longer(-extra_param_code, names_to = "col", values_to = "value") %>%
  filter(!is.na(value)) %>%
  distinct(extra_param_code, col, value) %>%
  count(extra_param_code, col) %>%
  filter(n > 1)

if (nrow(changed) > 0) {
  message("\nnote: these changed mid-run for some participants (first value kept):")
  print(distinct(changed, col), n = Inf)
}

finished <- map(finished, ~ select(.x, -any_of(c(derivable, session))))

# --- Merge: one column set per component, joined on the participant code ----
# Not guaranteed one row per participant: a code with two finished rows in a
# table (Part1 can do this) will fan out. That is accepted here.
prefix_cols <- function(df, tbl) {
  keep <- c("extra_param_code")
  rename_with(
    df,
    ~ paste0(sub("^Kanji_", "", tbl), "_", .x),
    .cols = setdiff(names(df), keep)
  )
}

kanji_merged <- imap(finished, ~ prefix_cols(.x, .y)) %>%
  reduce(full_join, by = "extra_param_code") %>%
  left_join(session_cols, by = "extra_param_code")


message("\nmerged: ", nrow(kanji_merged), " rows, ",
        ncol(kanji_merged), " columns, ",
        n_distinct(kanji_merged$extra_param_code), " participant codes")

dup <- imap(finished, ~ mutate(select(.x, extra_param_code), step = .y)) %>%
  bind_rows() %>% count(step, extra_param_code) %>% filter(n > 1)
if (nrow(dup) > 0) {
  message("\nCodes with more than one finished row:")
  print(dup, n = Inf)
}

# --- One workbook, one tab per step -----------------------------------------
# Tabs are numbered so they sit in the order participants move through the
# study, with the joined sheet first. Excel caps a tab name at 31 characters
# and forbids : \ / ? * [ ], so the names below are kept short deliberately.
sheets <- c(
  list(Merged = kanji_merged),
  set_names(
    finished,
    sprintf("%02d_%s", match(names(finished), tables),
            sub("^Kanji_", "", names(finished)))
  )
)

out_file <- file.path(out_dir, "kanji_export.xlsx")
write_xlsx(sheets, out_file)

message("\nTabs written:")
iwalk(sheets, ~ message(sprintf("  %-22s %3d rows, %3d cols",
                                .y, nrow(.x), ncol(.x))))
message("\nwrote ", out_file)

# --- Prize draw, exported on its own ----------------------------------------
# The e-mail addresses go in their own workbook and are never joined to the
# study data. The draw survey stores no participant code precisely so that an
# address cannot be traced back to someone's answers; putting it in the same
# file as the answers would undo that. Only the address and the date are kept.
draw <- fetch_table("Kanji_PrizeDraw")

if (is.null(draw) || nrow(draw) == 0) {
  message("prize draw: no entries, nothing written")
} else {
  draw <- draw %>%
    filter(triggerType == "finished") %>%
    filter(!is.na(email), email != "") %>%
    select(email, entry_date) %>%
    distinct(email, .keep_all = TRUE)

  draw_file <- file.path(out_dir, "kanji_prize_draw.xlsx")
  write_xlsx(list(PrizeDraw = draw), draw_file)
  message("prize draw: ", nrow(draw), " e-mail addresses")
  message("wrote ", draw_file)
}
