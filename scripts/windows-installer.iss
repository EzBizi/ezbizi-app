; EzBizi — Windows installer
;
; `flutter build windows` produces a folder, not something anybody can install:
; an exe beside a DLL and a data directory, all of which must travel together.
; This wraps that folder into one file a person can double-click.
;
; Driven by scripts/release.sh, which passes the version and the build folder:
;
;   ISCC.exe /DAppVersion=0.0.0097 /DSourceDir=...\Release /DOutputDir=...\dist
;
; Inno Setup 6, already on the build machine. Chosen over MSIX because an MSIX
; will not install at all without a trusted certificate, while an unsigned exe
; installs after a SmartScreen warning — a worse first impression, but a working
; one, and it costs no code signing certificate to find out.
;
; Signed since 26 Oct 2026 with the DigiCert "EzBizi LLC" certificate on the
; SafeNet token. release.sh signs ezbizi.exe before this runs and the finished
; installer after it, so Inno's own SignTool is not used here.
; ponytail: the uninstaller Inno writes stays unsigned. It is a per-user install,
; so no UAC prompt shows it. Move signing to Inno's SignTool= if that changes.

#ifndef AppVersion
  #error AppVersion must be passed with /DAppVersion=
#endif
#ifndef SourceDir
  #error SourceDir must be passed with /DSourceDir=
#endif
#ifndef OutputDir
  #error OutputDir must be passed with /DOutputDir=
#endif
#ifndef AssetDir
  #error AssetDir must be passed with /DAssetDir= (assets/installer)
#endif

[Setup]
; Never reuse this GUID for another product — it is how Windows recognises an
; upgrade of EzBizi rather than a second copy of it.
AppId={{8F3A6C21-4D7E-4B90-9E52-1C6A0B7D4E38}
AppName=EzBizi
AppVersion={#AppVersion}
AppVerName=EzBizi {#AppVersion}
AppPublisher=EZBIZI LLC
AppPublisherURL=https://www.ezbizi.com/
AppSupportURL=https://www.ezbizi.com/
DefaultDirName={autopf}\EzBizi
DefaultGroupName=EzBizi
DisableProgramGroupPage=yes
OutputDir={#OutputDir}
OutputBaseFilename=EzBizi-{#AppVersion}-windows-x64
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
; Per-user, always, and with no dialog about it.
;
; PrivilegesRequiredOverridesAllowed=dialog was here and it is what made an
; automatic update stop and ask. It adds Inno's "Select Setup Install Mode"
; page — install for all users, or just me — and every in-app update runs this
; installer, so the app closed itself to be replaced and then put a question on
; screen that nobody was there to answer. Jose's instruction on 3 Sep: an
; update installs itself, no decision from the user.
;
; PrivilegesRequired=lowest keeps the reason the override existed: a per-user
; install needs no administrator, so a field technician on a locked-down laptop
; can still install it. Removing the OVERRIDE removes the question, not the
; capability.
PrivilegesRequired=lowest
UninstallDisplayName=EzBizi

; ── Running the installer while EzBizi is open ───────────────────
;
; This is the normal case, not the edge case: the in-app updater runs this
; installer FROM the app it is about to replace, and a person double-clicking a
; downloaded installer usually has the app open too.
;
; force, not yes. With `yes` Inno shows a "the following applications are using
; files that need to be updated" page and waits for a click — which nobody sees
; during a /VERYSILENT update, so the update appears to do nothing. `force`
; closes the app itself so its files can be replaced.
CloseApplications=force

; no, deliberately. This asks the Windows Restart Manager to relaunch what it
; shut down, and it did not — an update on 1 Sep closed the app and left it
; closed. Rather than debug RM, the [Run] entry below starts the app, which is
; explicit and testable. Two mechanisms racing would risk two copies running;
; one that provably works beats one that sometimes does.
RestartApplications=no

; Only one installer at a time. Two copies racing to replace the same files is
; how a half-written install happens, and the updater can fire while somebody is
; already running one by hand.
SetupMutex=EzBiziSetup,Global\EzBiziSetup

; A log in %TEMP% every run. An installer that fails silently gives you nothing
; to go on, and the ones that fail are exactly the ones nobody can reproduce.
SetupLogging=yes

; ── Looking like real software ───────────────────────────────────
;
; The icon on the installer's own .exe, the icon Windows shows in Apps &
; features, and the artwork on the wizard pages. Several sizes of each image,
; comma-separated, so Inno picks per display DPI instead of scaling one up —
; a blurry banner is the tell that gives away a hand-rolled installer.
SetupIconFile={#AssetDir}\ezbizi.ico
UninstallDisplayIcon={app}\ezbizi.exe
WizardImageFile={#AssetDir}\wizard-large-1x.bmp,{#AssetDir}\wizard-large-2x.bmp,{#AssetDir}\wizard-large-3x.bmp
WizardSmallImageFile={#AssetDir}\wizard-small-1x.bmp,{#AssetDir}\wizard-small-2x.bmp,{#AssetDir}\wizard-small-3x.bmp

; The welcome page is off by default in the modern style. On, because this is
; the first thing a new person sees of EzBizi and a wizard that opens straight
; into a directory picker feels like a zip file with ambitions.
DisableWelcomePage=no

; What Windows shows in the file's Properties > Details, and what SmartScreen
; quotes. Blank fields there read as unsigned freeware.
VersionInfoVersion={#AppVersion}
VersionInfoCompany=EZBIZI LLC
VersionInfoDescription=EzBizi Setup
VersionInfoProductName=EzBizi
VersionInfoCopyright=EZBIZI LLC

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"

; Start with Windows, offered as a checkbox rather than done silently.
;
; A shortcut in the Startup folder, not a Run key in the registry. Both start
; the app; only this one appears in Task Manager's Startup tab where a person
; can see it and turn it off, and only this one is removed by uninstalling.
; Something that launches itself every morning and cannot be found is the
; behaviour people install cleanup tools to stop.
;
; Checked by default because this is a work tool people open every day anyway.
; UsePreviousTasks is on by default in Inno, so somebody who unchecks it stays
; unchecked through every later update — including the silent ones the in-app
; updater runs, which would otherwise quietly switch it back on.
Name: "startupicon"; Description: "Start EzBizi when I sign in to Windows"; GroupDescription: "Startup:"

[Files]
; The whole build folder. Flutter's data\ directory holds the compiled Dart and
; every asset; leave one file behind and the app starts and then dies looking
; for it, so this is deliberately a recursive sweep rather than a named list.
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\EzBizi"; Filename: "{app}\ezbizi.exe"
Name: "{group}\Uninstall EzBizi"; Filename: "{uninstallexe}"
Name: "{autodesktop}\EzBizi"; Filename: "{app}\ezbizi.exe"; Tasks: desktopicon
Name: "{userstartup}\EzBizi"; Filename: "{app}\ezbizi.exe"; Tasks: startupicon

[Run]
; NO skipifsilent. That flag is why an in-app update installed and never came
; back: every update runs this installer silently, and skipifsilent skips this
; line in exactly that case, so the app closed itself to be replaced and then
; nothing started it. Reported 1 Sep after updating 0.0.0129 -> 0.0.0131.
;
; postinstall keeps the "Start EzBizi" tickbox for someone running the
; installer by hand; without skipifsilent it also runs on a silent install,
; which is the only way the app comes back on its own.
Filename: "{app}\ezbizi.exe"; Description: "Start EzBizi"; Flags: nowait postinstall
