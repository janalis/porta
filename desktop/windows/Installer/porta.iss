; Installs Porta for the current user: no administrator rights, nothing outside the user's profile.
#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
#ifndef SourceDir
  #define SourceDir "..\App\bin\Release\net48"
#endif
#ifndef RuntimeDir
  #define RuntimeDir "runtime"
#endif
#ifndef OutputDir
  #define OutputDir "output"
#endif

[Setup]
AppId={{7C0E6A52-5B1C-4C63-9C3E-6E0C8E6F4A11}
AppName=Porta
AppVersion={#AppVersion}
AppPublisher=The Porta authors
AppPublisherURL=https://janalis.github.io/porta/
DefaultDirName={localappdata}\Programs\Porta
DefaultGroupName=Porta
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#OutputDir}
OutputBaseFilename=porta-desktop-{#AppVersion}-windows
SetupIconFile=..\..\assets\icon.ico
UninstallDisplayIcon={app}\Porta.exe
Compression=lzma2
SolidCompression=yes
WizardStyle=modern

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion
Source: "{#RuntimeDir}\*"; DestDir: "{app}\runtime"; Flags: recursesubdirs ignoreversion

[Icons]
Name: "{userprograms}\Porta"; Filename: "{app}\Porta.exe"
Name: "{userdesktop}\Porta"; Filename: "{app}\Porta.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; Flags: unchecked

[Run]
Filename: "{app}\Porta.exe"; Description: "Start Porta"; Flags: nowait postinstall skipifsilent
