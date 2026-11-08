' Detach php -S the same way Install.vbs does. The install worker must not own the process.
If WScript.Arguments.Count < 3 Then
  WScript.Quit 1
End If

php = WScript.Arguments(0)
root = WScript.Arguments(1)
port = WScript.Arguments(2)

Set sh = CreateObject("WScript.Shell")
cmd = "cmd /c cd /d """ & root & """ && """ & php & """ -S 127.0.0.1:" & port _
  & " -t """ & root & """ """ & root & "\server.php"""
sh.Run cmd, 0, False
WScript.Quit 0
