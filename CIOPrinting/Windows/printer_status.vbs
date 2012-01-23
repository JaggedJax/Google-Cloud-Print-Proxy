strComputer = "."
Set objWMIService = GetObject("winmgmts:" _
    & "{impersonationLevel=impersonate}!\\" & strComputer & "\root\cimv2")
Set colPrintQueues =  objWMIService.ExecQuery _
    ("Select * from Win32_Printer Where " & _
        "Name <> '_Total'")
For Each objPrintQueue in colPrintQueues
    Wscript.Echo objPrintQueue.Name & "x::x" & objPrintQueue.ExtendedDetectedErrorState
Next