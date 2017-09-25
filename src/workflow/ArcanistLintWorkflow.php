<?php

/**
 * Runs lint rules on changes.
 */
final class ArcanistLintWorkflow extends ArcanistWorkflow {

  const RESULT_OKAY       = 0;
  const RESULT_WARNINGS   = 1;
  const RESULT_ERRORS     = 2;
  const RESULT_SKIP       = 3;

  const DEFAULT_SEVERITY = ArcanistLintSeverity::SEVERITY_ADVICE;

  private $unresolvedMessages;
  private $shouldAmendChanges = false;
  private $shouldAmendWithoutPrompt = false;
  private $shouldAmendAutofixesWithoutPrompt = false;
  private $engine;

  public function getWorkflowName() {
    return 'lint';
  }

  public function setShouldAmendChanges($should_amend) {
    $this->shouldAmendChanges = $should_amend;
    return $this;
  }

  public function setShouldAmendWithoutPrompt($should_amend) {
    $this->shouldAmendWithoutPrompt = $should_amend;
    return $this;
  }

  public function setShouldAmendAutofixesWithoutPrompt($should_amend) {
    $this->shouldAmendAutofixesWithoutPrompt = $should_amend;
    return $this;
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **lint** [__options__] [__paths__]
      **lint** [__options__] --rev [__rev__]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, svn, hg
          Run static analysis on changes to check for mistakes. If no files
          are specified, lint will be run on all files which have been modified.
EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'lintall' => array(
        'help' => pht(
          'Show all lint warnings, not just those on changed lines. When '.
          'paths are specified, this is the default behavior.'),
      ),
      'rev' => array(
        'param' => 'revision',
        'help' => pht('Lint changes since a specific revision.'),
        'supports' => array(
          'git',
          'hg',
        ),
        'nosupport' => array(
          'svn' => pht('Lint does not currently support %s in SVN.', '--rev'),
        ),
      ),
      'output' => array(
        'param' => 'format',
        'help' => pht(
          "With 'summary', show lint warnings in a more compact format. ".
          "With 'json', show lint warnings in machine-readable JSON format. ".
          "With 'none', show no lint warnings. ".
          "With 'compiler', show lint warnings in suitable for your editor. ".
          "With 'xml', show lint warnings in the Checkstyle XML format."),
      ),
      'outfile' => array(
        'param' => 'path',
        'help' => pht(
          'Output the linter results to a file. Defaults to stdout.'),
      ),
      'engine' => array(
        'param' => 'classname',
        'help' => pht('Override configured lint engine for this project.'),
      ),
      'apply-patches' => array(
        'help' => pht(
          'Apply patches suggested by lint to the working copy without '.
          'prompting.'),
        'conflicts' => array(
          'never-apply-patches' => true,
        ),
      ),
      'never-apply-patches' => array(
        'help' => pht('Never apply patches suggested by lint.'),
        'conflicts' => array(
          'apply-patches' => true,
        ),
      ),
      'amend-all' => array(
        'help' => pht(
          'When linting git repositories, amend HEAD with all patches '.
          'suggested by lint without prompting.'),
      ),
      'amend-autofixes' => array(
        'help' => pht(
          'When linting git repositories, amend HEAD with autofix '.
          'patches suggested by lint without prompting.'),
      ),
      'everything' => array(
        'help' => pht('Lint all files in the project.'),
        'conflicts' => array(
          'rev' => pht('%s lints all files', '--everything'),
        ),
      ),
      'severity' => array(
        'param' => 'string',
        'help' => pht(
          "Set minimum message severity. One of: %s. Defaults to '%s'.",
          sprintf(
            "'%s'",
            implode(
              "', '",
              array_keys(ArcanistLintSeverity::getLintSeverities()))),
          self::DEFAULT_SEVERITY),
      ),
      '*' => 'paths',
    );
  }

  public function requiresAuthentication() {
    return false;
  }

  public function requiresWorkingCopy() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function run() {
    $console = PhutilConsole::getConsole();
    $working_copy = $this->getWorkingCopy();
    $configuration_manager = $this->getConfigurationManager();

    $engine = $this->newLintEngine($this->getArgument('engine'));

    $rev = $this->getArgument('rev');
    $paths = $this->getArgument('paths');
    $everything = $this->getArgument('everything');
    if ($everything && $paths) {
      throw new ArcanistUsageException(
        pht(
          'You can not specify paths with %s. The %s flag lints every file.',
          '--everything',
          '--everything'));
    }

    if ($rev !== null) {
      $this->parseBaseCommitArgument(array($rev));
    }

    // Sometimes, we hide low-severity messages which occur on lines which
    // were not changed. This is the default behavior when you run "arc lint"
    // with no arguments: if you touched a file, but there was already some
    // minor warning about whitespace or spelling elsewhere in the file, you
    // don't need to correct it.

    // In other modes, notably "arc lint <file>", this is not the defualt
    // behavior. If you ask us to lint a specific file, we show you all the
    // lint messages in the file.

    // You can change this behavior with various flags, including "--lintall",
    // "--rev", and "--everything".
    if ($this->getArgument('lintall')) {
      $lint_all = true;
    } else if ($rev !== null) {
      $lint_all = false;
    } else if ($paths || $everything) {
      $lint_all = true;
    } else {
      $lint_all = false;
    }

    if ($everything) {
      $paths = iterator_to_array($this->getRepositoryAPI()->getAllFiles());
    } else {
      $paths = $this->selectPathsForWorkflow($paths, $rev);
    }

    $this->engine = $engine;

    $engine->setMinimumSeverity(
      $this->getArgument('severity', self::DEFAULT_SEVERITY));

    // Propagate information about which lines changed to the lint engine.
    // This is used so that the lint engine can drop warning messages
    // concerning lines that weren't in the change.
    $engine->setPaths($paths);
    if ($lint_all) {
      foreach ($paths as $path) {
        // Note that getChangedLines() returns null to indicate that a file
        // is binary or a directory (i.e., changed lines are not relevant).
        $engine->setPathChangedLines(
          $path,
          $this->getChangedLines($path, 'new'));
      }
    }

    $failed = null;
    try {
      $engine->run();
    } catch (Exception $ex) {
      $failed = $ex;
    }

    $results = $engine->getResults();

    if ($this->getArgument('never-apply-patches')) {
      $apply_patches = false;
    } else {
      $apply_patches = true;
    }

    if ($this->getArgument('apply-patches')) {
      $prompt_patches = false;
    } else {
      $prompt_patches = true;
    }

    if ($this->getArgument('amend-all')) {
      $this->shouldAmendChanges = true;
      $this->shouldAmendWithoutPrompt = true;
    }

    if ($this->getArgument('amend-autofixes')) {
      $prompt_autofix_patches = false;
      $this->shouldAmendChanges = true;
      $this->shouldAmendAutofixesWithoutPrompt = true;
    } else {
      $prompt_autofix_patches = true;
    }

    $repository_api = $this->getRepositoryAPI();
    if ($this->shouldAmendChanges) {
      $this->shouldAmendChanges = $repository_api->supportsAmend() &&
        !$this->isHistoryImmutable();
    }

    $wrote_to_disk = false;

    switch ($this->getArgument('output')) {
      case 'json':
        $renderer = new ArcanistJSONLintRenderer();
        $prompt_patches = false;
        $apply_patches = $this->getArgument('apply-patches');
        break;
      case 'summary':
        $renderer = new ArcanistSummaryLintRenderer();
        break;
      case 'none':
        $prompt_patches = false;
        $apply_patches = $this->getArgument('apply-patches');
        $renderer = new ArcanistNoneLintRenderer();
        break;
      case 'compiler':
        $renderer = new ArcanistCompilerLintRenderer();
        $prompt_patches = false;
        $apply_patches = $this->getArgument('apply-patches');
        break;
      case 'xml':
        $renderer = new ArcanistCheckstyleXMLLintRenderer();
        $prompt_patches = false;
        $apply_patches = $this->getArgument('apply-patches');
        break;
      default:
        $renderer = new ArcanistConsoleLintRenderer();
        $renderer->setShowAutofixPatches($prompt_autofix_patches);
        break;
    }

    $all_autofix = true;
    $tmp = null;

    if ($this->getArgument('outfile') !== null) {
      $tmp = id(new TempFile())
        ->setPreserveFile(true);
    }

    $preamble = $renderer->renderPreamble();
    if ($tmp) {
      Filesystem::appendFile($tmp, $preamble);
    } else {
      $console->writeOut('%s', $preamble);
    }

    foreach ($results as $result) {
      $result_all_autofix = $result->isAllAutofix();

      if (!$result->getMessages() && !$result_all_autofix) {
        continue;
      }

      if (!$result_all_autofix) {
        $all_autofix = false;
      }

      $lint_result = $renderer->renderLintResult($result);
      if ($lint_result) {
        if ($tmp) {
          Filesystem::appendFile($tmp, $lint_result);
        } else {
          $console->writeOut('%s', $lint_result);
        }
      }

      if ($apply_patches && $result->isPatchable()) {
        $patcher = ArcanistLintPatcher::newFromArcanistLintResult($result);
        $old_file = $result->getFilePathOnDisk();

        if ($prompt_patches &&
            !($result_all_autofix && !$prompt_autofix_patches)) {
          if (!Filesystem::pathExists($old_file)) {
            $old_file = '/dev/null';
          }
          $new_file = new TempFile();
          $new = $patcher->getModifiedFileContent();
          Filesystem::writeFile($new_file, $new);

          // TODO: Improve the behavior here, make it more like
          // difference_render().
          list(, $stdout, $stderr) =
            exec_manual('diff -u %s %s', $old_file, $new_file);
          $console->writeOut('%s', $stdout);
          $console->writeErr('%s', $stderr);

          $prompt = pht(
            'Apply this patch to %s?',
            phutil_console_format('__%s__', $result->getPath()));
          if (!phutil_console_confirm($prompt, $default_no = false)) {
            continue;
          }
        }

        $patcher->writePatchToDisk();
        $wrote_to_disk = true;
      }
    }

    $postamble = $renderer->renderPostamble();
    if ($tmp) {
      Filesystem::appendFile($tmp, $postamble);
      Filesystem::rename($tmp, $this->getArgument('outfile'));
    } else {
      $console->writeOut('%s', $postamble);
    }

    if ($wrote_to_disk && $this->shouldAmendChanges) {
      if ($this->shouldAmendWithoutPrompt ||
          ($this->shouldAmendAutofixesWithoutPrompt && $all_autofix)) {
        $console->writeOut(
          "<bg:yellow>** %s **</bg> %s\n",
          pht('LINT NOTICE'),
          pht('Automatically amending HEAD with lint patches.'));
        $amend = true;
      } else {
        $amend = phutil_console_confirm(pht('Amend HEAD with lint patches?'));
      }

      if ($amend) {
        if ($repository_api instanceof ArcanistGitAPI) {
          // Add the changes to the index before amending
          $repository_api->execxLocal('add -u');
        }

        $repository_api->amendCommit();
      } else {
        throw new ArcanistUsageException(
          pht(
            'Sort out the lint changes that were applied to the working '.
            'copy and relint.'));
      }
    }

    if ($this->getArgument('output') == 'json') {
      // NOTE: Required by save_lint.php in Phabricator.
      return 0;
    }

    if ($failed) {
      if ($failed instanceof ArcanistNoEffectException) {
        if ($renderer instanceof ArcanistNoneLintRenderer) {
          return 0;
        }
      }
      throw $failed;
    }

    $unresolved = array();
    $has_warnings = false;
    $has_errors = false;

    foreach ($results as $result) {
      foreach ($result->getMessages() as $message) {
        if (!$message->isPatchApplied()) {
          if ($message->isError()) {
            $has_errors = true;
          } else if ($message->isWarning()) {
            $has_warnings = true;
          }
          $unresolved[] = $message;
        }
      }
    }
    $this->unresolvedMessages = $unresolved;

    // Take the most severe lint message severity and use that
    // as the result code.
    if ($has_errors) {
      $result_code = self::RESULT_ERRORS;
    } else if ($has_warnings) {
      $result_code = self::RESULT_WARNINGS;
    } else {
      $result_code = self::RESULT_OKAY;
    }

    if (!$this->getParentWorkflow()) {
      if ($result_code == self::RESULT_OKAY) {
        $console->writeOut('%s', $renderer->renderOkayResult());
      }
    }

    return $result_code;
  }

  public function getUnresolvedMessages() {
    return $this->unresolvedMessages;
  }

}
