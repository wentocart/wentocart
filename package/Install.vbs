Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")

folder = fso.GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = folder

php = ResolvePhp(folder)
helper = ""
If fso.FileExists(folder & "\helper.php") Then
  helper = folder & "\helper.php"
ElseIf fso.FileExists(folder & "\scripts\install\helper.php") Then
  helper = folder & "\scripts\install\helper.php"
End If
If php <> "" And helper <> "" Then
  On Error Resume Next
  Set exec = sh.Exec("""" & php & """ """ & helper & """ php")
  If Err.Number = 0 Then
    best = Trim(exec.StdOut.ReadLine)
    If best <> "" And fso.FileExists(best) Then php = best
  End If
  On Error GoTo 0
End If

If php = "" Then
  msg = "PHP 8.3 or newer was not found." & vbCrLf & vbCrLf & _
    "1) Install PHP 8.3 or newer and add it to PATH, then run this installer again." & vbCrLf & vbCrLf & _
    "2) Or create a file named php.path in this folder and put the full path to the PHP program on the first line." & vbCrLf & vbCrLf & _
    "3) Or point your web server at this folder and open that site address. This helper is not required."
  MsgBox msg, 16, "WentoCart Installer"
  WScript.Quit 1
End If

port = FindFreePort(php, folder, SavedListenPort(folder))
If port = "" Then
  MsgBox "Could not find a free port between 8099 and 8199. Close the old installer helper and try again.", 16, "WentoCart Installer"
  WScript.Quit 1
End If

sh.Run HelperServerCommand(php, folder, port), 0, False
WScript.Sleep 2000
sh.Run "http://127.0.0.1:" & port & "/", 1, False
WScript.Quit 0

Function SavedListenPort(root)
  Dim fso2, ts, line, url, p, rest, colon, slash, part
  Set fso2 = CreateObject("Scripting.FileSystemObject")
  SavedListenPort = ""
  If fso2.FileExists(root & "\helper.port") Then
    Set ts = fso2.OpenTextFile(root & "\helper.port", 1)
    If Not ts.AtEndOfStream Then line = Trim(ts.ReadLine)
    ts.Close
    If line <> "" And IsNumeric(line) Then
      SavedListenPort = CStr(CLng(line))
      Exit Function
    End If
  End If
  If Not fso2.FileExists(root & "\.env") Then Exit Function
  Set ts = fso2.OpenTextFile(root & "\.env", 1)
  Do While Not ts.AtEndOfStream
    line = Trim(ts.ReadLine)
    If UCase(Left(line, 8)) = "APP_URL=" Then
      url = Trim(Mid(line, 9))
      If Left(url, 1) = """" And Right(url, 1) = """" Then url = Mid(url, 2, Len(url) - 2)
      p = InStr(1, url, "://")
      If p > 0 Then
        rest = Mid(url, p + 3)
        colon = InStr(rest, ":")
        slash = InStr(rest, "/")
        If colon > 0 Then
          If slash = 0 Or colon < slash Then
            If slash > colon Then
              part = Mid(rest, colon + 1, slash - colon - 1)
            Else
              part = Mid(rest, colon + 1)
            End If
            If part <> "" And IsNumeric(part) Then SavedListenPort = CStr(CLng(part))
          End If
        End If
      End If
      Exit Do
    End If
  Loop
  ts.Close
End Function

Function FindFreePort(phpBin, root, preferred)
  Dim fso2, script, cmd, exec, picked
  Set fso2 = CreateObject("Scripting.FileSystemObject")
  FindFreePort = ""
  If fso2.FileExists(root & "\helper.php") Then
    script = root & "\helper.php"
  ElseIf fso2.FileExists(root & "\scripts\install\helper.php") Then
    script = root & "\scripts\install\helper.php"
  Else
    If preferred <> "" Then
      FindFreePort = preferred
    Else
      FindFreePort = "8099"
    End If
    Exit Function
  End If
  cmd = """" & phpBin & """ """ & script & """ port"
  If preferred <> "" Then cmd = cmd & " " & preferred
  On Error Resume Next
  Set exec = CreateObject("WScript.Shell").Exec(cmd)
  If Err.Number = 0 Then
    picked = Trim(exec.StdOut.ReadLine)
    If picked <> "" And IsNumeric(picked) Then FindFreePort = CStr(CLng(picked))
  End If
  On Error GoTo 0
End Function

Function HelperServerCommand(phpBin, root, listenPort)
  ' Windows php.exe often starts in C:\php, so a bare "server.php" is not found.
  HelperServerCommand = "cmd /c cd /d """ & root & """ && """ & phpBin & """ -S 127.0.0.1:" & listenPort & " -t """ & root & """ """ & root & "\server.php"""
End Function

Function ResolvePhp(root)
  Dim p
  p = ReadPhpPathFile(root & "\php.path")
  If p <> "" Then
    ResolvePhp = p
    Exit Function
  End If

  p = PhpFromWhere()
  If p <> "" Then
    ResolvePhp = p
    Exit Function
  End If

  p = PhpFromCommonLocations()
  ResolvePhp = p
End Function

Function ReadPhpPathFile(path)
  Dim fso2, ts, line, cand
  Set fso2 = CreateObject("Scripting.FileSystemObject")
  ReadPhpPathFile = ""
  If Not fso2.FileExists(path) Then Exit Function
  Set ts = fso2.OpenTextFile(path, 1)
  Do While Not ts.AtEndOfStream
    line = Trim(ts.ReadLine)
    If line <> "" And Left(line, 1) <> "#" Then
      cand = line
      If Left(cand, 1) = """" And Right(cand, 1) = """" Then
        cand = Mid(cand, 2, Len(cand) - 2)
      End If
      If fso2.FileExists(cand) Then
        ReadPhpPathFile = cand
        ts.Close
        Exit Function
      End If
    End If
  Loop
  ts.Close
End Function

Function PhpFromWhere()
  Dim sh2, exec, line
  Set sh2 = CreateObject("WScript.Shell")
  PhpFromWhere = ""
  On Error Resume Next
  Set exec = sh2.Exec("cmd /c where php 2>NUL")
  If Err.Number = 0 Then
    Do While Not exec.StdOut.AtEndOfStream
      line = Trim(exec.StdOut.ReadLine)
      If line <> "" And CreateObject("Scripting.FileSystemObject").FileExists(line) Then
        PhpFromWhere = line
        Exit Do
      End If
    Loop
  End If
  On Error GoTo 0
End Function

Function PhpFromCommonLocations()
  Dim fso2, sh2, candidates, i, drive, d, base, subf, folder, file
  Set fso2 = CreateObject("Scripting.FileSystemObject")
  Set sh2 = CreateObject("WScript.Shell")
  PhpFromCommonLocations = ""

  candidates = Array( _
    sh2.ExpandEnvironmentStrings("%ProgramFiles%\php\php.exe"), _
    sh2.ExpandEnvironmentStrings("%ProgramFiles(x86)%\php\php.exe"), _
    sh2.ExpandEnvironmentStrings("%LOCALAPPDATA%\Programs\php\php.exe"), _
    sh2.ExpandEnvironmentStrings("%LOCALAPPDATA%\Programs\PHP\php.exe") _
  )

  For i = 0 To UBound(candidates)
    If fso2.FileExists(candidates(i)) Then
      PhpFromCommonLocations = candidates(i)
      Exit Function
    End If
  Next

  For Each drive In fso2.Drives
    If drive.IsReady And (drive.DriveType = 2 Or drive.DriveType = 1 Or drive.DriveType = 3) Then
      d = drive.Path
      If Right(d, 1) <> "\" Then d = d & "\"

      Dim fixed
      fixed = Array( _
        d & "php\php.exe", _
        d & "PHP\php.exe", _
        d & "xampp\php\php.exe", _
        d & "XAMPP\php\php.exe", _
        d & "wamp64\bin\php\php.exe", _
        d & "wamp\bin\php\php.exe", _
        d & "laragon\bin\php\php.exe" _
      )
      For i = 0 To UBound(fixed)
        If fso2.FileExists(fixed(i)) Then
          PhpFromCommonLocations = fixed(i)
          Exit Function
        End If
      Next

      ' Laragon / WAMP versioned folders: D:\laragon\bin\php\php-8.3.x\php.exe
      Dim roots
      roots = Array(d & "laragon\bin\php", d & "wamp64\bin\php", d & "wamp\bin\php")
      For Each base In roots
        If fso2.FolderExists(base) Then
          For Each subf In fso2.GetFolder(base).SubFolders
            file = subf.Path & "\php.exe"
            If fso2.FileExists(file) Then
              PhpFromCommonLocations = file
              Exit Function
            End If
          Next
        End If
      Next
    End If
  Next
End Function
