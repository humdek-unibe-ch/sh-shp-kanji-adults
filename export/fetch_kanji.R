# Kanji Adults — pull every study table over the SelfHelp API and write one
# Excel file per recall block and per questionnaire.
#
# HOW TO USE
#   1. Fill in the two settings below.
#   2. Run the whole script.
#        In RStudio: open this file and click "Source".
#        Elsewhere:  Rscript fetch_kanji.R
#
#   Any R packages it needs are installed automatically the first time.
#
#   It writes these next to this script:
#     kanji_data/recall/
#       kanji_recall.xlsx        every recall trial, one row each, laid out like
#                                  Example_Datafile_Kanji.xlsx: practice, then
#                                  list A, then list B
#       kanji_practice.xlsx      the same trials and layout, one recall block
#       kanji_recall_A.xlsx        per file
#       kanji_recall_B.xlsx
#     kanji_data/questionnaires/
#       kanji_part1.xlsx         one per questionnaire, one row per participant:
#       kanji_demographics.xlsx    part1, demographics, pause1, pause2, pause3
#       kanji_pause1.xlsx …        and part2
#     kanji_prize_draw.xlsx      the prize draw e-mail addresses, outside
#                                  kanji_data/ so sharing that folder never
#                                  shares them

# --- SETTINGS: fill these in -----------------------------------------------

# Your personal API token, from the development team.
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
    "If you do not have one, ask the development team.",
    call. = FALSE
  )
}

library(httr)
library(jsonlite)
library(dplyr)
library(purrr)
library(tidyr)
library(writexl)

# Write the files next to this script rather than wherever R happens to be.
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
  # Off the VPN the connection hangs rather than failing, so cap the wait.
  r <- tryCatch(
    GET(
      paste0(base_url, "/api/data/table/", tbl),
      add_headers(`X-API-Key` = api_key),
      config(connecttimeout = 20)
    ),
    error = function(e) stop(
      "Could not reach the study website at ", base_url, "\n",
      "Connect the VPN, check the base_url line, and run the script again.\n(",
      conditionMessage(e), ")", call. = FALSE)
  )
  stop_for_status(r)
  body <- content(r, as = "text", encoding = "UTF-8")
  parsed <- fromJSON(body, simplifyDataFrame = TRUE)

  # A wrong token still gets HTTP 200, with the 401 inside the body.
  if (identical(as.integer(parsed$status), 401L)) {
    stop("Server rejected the token.\n",
         "Re-paste it in the api_key line near the top, or ask for a new one.",
         call. = FALSE)
  }
  if (!identical(parsed$status, 200L) && !identical(parsed$status, 200)) {
    stop(sprintf("%s: API returned %s (%s)", tbl, parsed$status, parsed$message))
  }
  if (length(parsed$response) == 0) return(NULL)

  df <- as_tibble(parsed$response)
  # _json duplicates the flat columns and nests badly in a data frame; _raw_data
  # is the full lab.js event log and is enormous. Drop both.
  df %>%
    select(-any_of(c("_json", "_raw_data"))) %>%
    mutate(source_table = tbl)
}

message("Fetching ", length(tables), " tables ...")
# lapply, not map: map buries a fetch error under its own backtrace.
raw <- lapply(set_names(tables), function(t) {
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
# Every table arrives with the same block of columns that say nothing about the
# answers. Two kinds are dropped:
#
#   derivable  fixed by which table the row came from, so the file name
#              already says it
#   session    the account and the device, the same on every page; the CMS
#              Data page still has them
#
# Kept per table: record_id, entry_date, triggerType and the per-page timings.
# Those genuinely differ page to page and are how a drop-out is located.
derivable <- c("source_table", "survey_generated_id", "labjs_generated_id",
               "extra_data_block", "id_actionTriggerTypes")

session <- c("id_users", "user_name", "user_code", "extra_data_slice",
             "extra_data_UserLanguage", "labjs_response_id",
             "_meta_pixel_ratio", "_meta_screen_width", "_meta_screen_height",
             "_meta_viewport_width", "_meta_viewport_height", "_meta_user_agent",
             "mobile", "mobile_web")

finished <- map(finished, ~ select(.x, -any_of(c(derivable, session))))

dup <- imap(finished, ~ mutate(select(.x, extra_param_code), step = .y)) %>%
  bind_rows() %>% count(step, extra_param_code) %>% filter(n > 1)
if (nrow(dup) > 0) {
  message("\nCodes with more than one finished row:")
  print(dup, n = Inf)
}

# --- Recall: one row per trial, laid out like Example_Datafile_Kanji.xlsx ----
# The task pages store each recall block as one JSON array. Each block gets its
# own file, and all three are stacked in the Recall file.
recall_blocks <- tribble(
  ~file,      ~table,        ~column,                      ~Stage,       ~List,
  "Practice", "Kanji_Task1", "extra_data_trials_practice", "practice",   NA_character_,
  "Recall_A", "Kanji_Task2", "extra_data_trials_recall_A", "experiment", "A",
  "Recall_B", "Kanji_Task4", "extra_data_trials_recall_B", "experiment", "B"
)

na_if_absent <- function(t, name) if (is.null(t[[name]])) NA else t[[name]]

parse_recall <- function(json) {
  t <- fromJSON(json)
  if (length(t) == 0) return(NULL)
  # Each block keeps the Qualtrics question numbers (Q22/Q23, Q42/Q43, Q2/Q3);
  # the "confidence after choice" key names both.
  q <- unlist(regmatches(names(t), regexec("^Reaktionszeit_(Q\\d+)_ab_(Q\\d+)_ms$", names(t))))
  conf <- q[2]; choice <- q[3]
  tibble(
    Trial      = t$trial,
    item       = t$item,
    chosen     = t[[paste0("Auswahl_", choice)]],
    side_chose = t$gewaehlt,
    side_right = t$korrekt_seite,
    Punkte     = t$korrekt,
    Confidence_Judgement = t[[paste0("Auswahl_", conf)]],
    Reaktionszeit_ms_Antwort = t[[paste0("Reaktionszeit_", choice, "_ms")]],
    Reaktionszeit_ms_Confidence_Judgement = t[[paste0("Reaktionszeit_", conf, "_ab_", choice, "_ms")]],
    # Recorded since the click timings were added; earlier runs have none.
    Zeit_sek_First_Click = na_if_absent(t, "Zeit_First_Click_ms") / 1000,
    Zeit_sek_Last_Click  = na_if_absent(t, "Zeit_Last_Click_ms") / 1000,
    Zeit_sek_Page_Submit = na_if_absent(t, "Zeit_Page_Submit_ms") / 1000,
    Click_Count          = na_if_absent(t, "Click_Count")
  )
}

# Progress is how far through the ten steps a code got, the nearest thing to
# Qualtrics' page-based percentage. Finished means part 2 was submitted.
progress <- imap(finished, ~ tibble(extra_param_code = unique(.x$extra_param_code),
                                    step = match(.y, tables))) %>%
  bind_rows() %>%
  group_by(extra_param_code) %>%
  summarise(Progress = round(100 * max(step) / length(tables)), .groups = "drop")

part2 <- finished[["Kanji_Part2"]]
id2 <- if (is.null(part2)) tibble(extra_param_code = character(), ID_2 = character()) else
  part2 %>% group_by(extra_param_code) %>%
    summarise(ID_2 = first(na.omit(na_if(ID_2, ""))), .groups = "drop")

participant <- progress %>%
  left_join(id2, by = "extra_param_code") %>%
  mutate(Finished = extra_param_code %in% id2$extra_param_code)

side_de <- c(left = "links", right = "rechts")

# Names as in Lists_Kanji_Adults.xlsx, which calls the Dunkelheit image Dunkel.
list_name <- function(x) if_else(x == "Dunkelheit", "Dunkel", x)

recall_block <- function(table, column, Stage, List) {
  d <- finished[[table]]
  if (is.null(d) || !column %in% names(d)) return(NULL)
  rows <- d %>%
    filter(!is.na(.data[[column]]), .data[[column]] != "") %>%
    # A task page is done once per code, so a second finished row is a retest.
    group_by(extra_param_code) %>%
    slice_max(record_id, n = 1, with_ties = FALSE) %>%
    ungroup() %>%
    transmute(extra_param_code, trials = map(.data[[column]], parse_recall)) %>%
    unnest(trials)
  if (nrow(rows) == 0) return(NULL)

  rows %>%
    left_join(participant, by = "extra_param_code") %>%
    mutate(
      ID_1 = extra_param_code, Stage = Stage, List = List,
      # List A shows Gefaehrlich twice, as the distractor for Vorsicht and as a
      # target; the target is Gefaehrlich_2, as in Qualtrics.
      Target = if_else(List %in% "A" & item == "Gefaehrlich", "Gefaehrlich_2", item),
      Antwort_recoded = list_name(sub("\\.jpg$", "", chosen)),
      Antwort_recoded = if_else(Target == "Gefaehrlich_2" & Antwort_recoded == "Gefaehrlich",
                                "Gefaehrlich_2", Antwort_recoded),
      Seite_Antwort = unname(side_de[side_chose]),
      Seite_Target  = unname(side_de[side_right]),
      across(starts_with("Zeit_sek_"), ~ round(.x, 3))
    ) %>%
    arrange(ID_1, Trial) %>%
    transmute(
      ID_1, ID_2, Progress, Finished, Stage, Trial, List, Target,
      Antwort_recoded, Seite_Antwort, Seite_Target, Punkte, Confidence_Judgement,
      Reaktionszeit_ms_Antwort, Reaktionszeit_ms_Confidence_Judgement,
      Zeit_sek_First_Click, Zeit_sek_Last_Click, Zeit_sek_Page_Submit, Click_Count
    )
}

recall_files <- recall_blocks %>%
  select(table, column, Stage, List) %>%
  pmap(recall_block) %>%
  set_names(recall_blocks$file) %>%
  compact()

kanji_recall <- bind_rows(recall_files)
if (nrow(kanji_recall) > 0) {
  kanji_recall <- arrange(kanji_recall, ID_1, Stage != "practice", List, Trial)
}

message("\nrecall: ", nrow(kanji_recall), " trials from ",
        if (nrow(kanji_recall) > 0) n_distinct(kanji_recall$ID_1) else 0, " participant codes")

# --- One file per recall block and per questionnaire ------------------------
# Each file holds one sheet named like the file. Excel caps a sheet name at 31
# characters and forbids : \ / ? * [ ], so the names are kept short.
survey_tables <- intersect(c("Kanji_Part1", "Kanji_Demographics", "Kanji_Pause1",
                             "Kanji_Pause2", "Kanji_Pause3", "Kanji_Part2"), names(finished))
recall_out <- c(if (nrow(kanji_recall) > 0) list(Recall = kanji_recall), recall_files)
survey_out <- set_names(finished[survey_tables], sub("^Kanji_", "", survey_tables))

# Excel locks a workbook while it is open, and writexl's error does not say so.
save_xlsx <- function(x, path) {
  tryCatch(write_xlsx(x, path), error = function(e) stop(
    "Could not write ", path, "\nIf it is open in Excel, close it and run the script again.\n(",
    conditionMessage(e), ")", call. = FALSE))
}

# Recall_A -> kanji_recall_A.xlsx
file_name <- function(name) paste0("kanji_", tolower(substr(name, 1, 1)), substring(name, 2), ".xlsx")

# The prize draw is written outside this folder, so sharing it never shares
# e-mail addresses.
data_dir <- file.path(out_dir, "kanji_data")

write_folder <- function(files, folder) {
  dir <- file.path(data_dir, folder)
  dir.create(dir, recursive = TRUE, showWarnings = FALSE)
  iwalk(files, function(df, name) {
    path <- file.path(dir, file_name(name))
    save_xlsx(set_names(list(df), name), path)
    message(sprintf("  %-40s %3d rows, %3d cols", file.path(folder, basename(path)), nrow(df), ncol(df)))
  })
}

message("\nFiles written:")
write_folder(recall_out, "recall")
write_folder(survey_out, "questionnaires")
message("in ", data_dir)

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
  save_xlsx(list(PrizeDraw = draw), draw_file)
  message("prize draw: ", nrow(draw), " e-mail addresses")
  message("wrote ", draw_file)
}
