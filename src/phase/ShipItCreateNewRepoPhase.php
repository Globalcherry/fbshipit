<?hh // strict

namespace Facebook\ShipIt;

final class ShipItCreateNewRepoPhase extends ShipItPhase {
  private ?string $sourceCommit = null;

  public function __construct(
    private ImmSet<string> $roots,
    private (function(ShipItChangeset):ShipItChangeset) $filter,
  ) {
    $this->skip();
  }

  <<__Override>>
  protected function isProjectSpecific(): bool {
    return false;
  }

  <<__Override>>
  public function getReadableName(): string {
    return 'Create a new git repo with an initial commit';
  }

  <<__Override>>
  public function getCLIArguments(): ImmVector<ShipItCLIArgument> {
    return ImmVector {
      shape(
        'long_name' => 'create-new-repo',
        'description' =>
          'Create a new git repository with a single commit, then exit',
        'write' => $_ ==> $this->unskip(),
      ),
      shape(
        'long_name' => 'create-new-repo-from-commit::',
        'description' =>
          'Like --create-new-repo, but at a specified source commit',
        'write' => $rev ==> {
            $this->sourceCommit = $rev;
            $this->unskip();
        },
      ),
      shape( // deprecated, renamed for consistency with verify
        'long_name' => 'special-create-new-repo',
        'replacement' => 'create-new-repo',
      ),
    };
  }

  <<__Override>>
  public function runImpl(
    ShipItBaseConfig $config,
  ): void {
    $temp_dir = self::createNewGitRepo(
      $config,
      $this->roots,
      $this->filter,
      $this->sourceCommit,
    );
    $temp_dir->keep();

    print('  New repository created at '.$temp_dir->getPath()."\n");
    exit(0);
  }

  public static function createNewGitRepo(
    ShipItBaseConfig $config,
    ImmSet<string> $roots,
    (function(ShipItChangeset):ShipItChangeset) $filter,
    ?string $revision = null,
  ): ShipItTempDir {
    $source = ShipItRepo::typedOpen(
      ShipItSourceRepo::class,
      $config->getSourcePath(),
      $config->getSourceBranch(),
    );

    print("  Exporting...\n");
    $export = $source->export($roots, $revision);
    $export_dir = $export['tempDir'];
    $rev = $export['revision'];

    print("  Creating unfiltered commit...\n");

    self::execSteps(
      $export_dir->getPath(),
      ImmVector {
        ImmVector { 'git', 'init' },
        ImmVector { 'git', 'add', '.' },
        ImmVector {
          'git',
          'commit',
          '-m',
          'initial unfiltered commit',
        },
      },
    );

    print("  Filtering...\n");
    $exported_repo = ShipItRepo::typedOpen(
      ShipItSourceRepo::class,
      $export_dir->getPath(),
      'master',
    );
    $changeset = $exported_repo->getChangesetFromID('HEAD');
    invariant($changeset !== null, 'got a null changeset :/');
    $changeset = $changeset->withID($rev);
    $changeset = $filter($changeset)->withSubject('Initial commit');
    $changeset = ShipItSync::addTrackingData($changeset, $rev);

    if ($config->isVerboseEnabled()) {
      $changeset->dumpDebugMessages();
    }

    print("  Creating new repo...\n");

    $filtered_dir = new ShipItTempDir('git-with-initial-commit');
    self::execSteps(
      $filtered_dir->getPath(),
      ImmVector { ImmVector { 'git', 'init' } },
    );
    $filtered_repo = ShipItRepo::typedOpen(
      ShipItDestinationRepo::class,
      $filtered_dir->getPath(),
      '--orphan=master',
    );
    $filtered_repo->commitPatch($changeset);

    return $filtered_dir;
  }

  private static function execSteps(
    string $path,
    ImmVector<ImmVector<string>> $steps,
  ): void {
    foreach ($steps as $step) {
      ShipItUtil::shellExec(
        $path,
        /* stdin = */ null,
        ShipItUtil::DONT_VERBOSE,
        ...$step,
      );
    }
  }
}
