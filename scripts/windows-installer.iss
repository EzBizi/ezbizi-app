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
; ponytail: unsigned. Buy an EV code signing certificate and add SignTool here
; when the SmartScreen warning starts costing more than the certificate.

#ifndef AppVersion
  #error AppVersion must be passed with /DAppVersion=
#endif
#ifndef SourceDir
  #error SourceDir must be passed with /DSourceDir=
#endif
#ifndef OutputDir
  #error OutputDir must be passed with /DOutputDir=
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
; Per-user by default so the installer does not need an administrator. A field
; technician on a locked-down laptop can install it themselves, which is the
; whole point of shipping a desktop build.
PrivilegesRequiredOverridesAllowed=dialog
PrivilegesRequired=lowest
UninstallDisplayName=EzBizi

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
Filename: "{app}\ezbizi.exe"; Description: "Start EzBizi"; Flags: nowait postinstall skipifsilent
