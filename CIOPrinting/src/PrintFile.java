import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.net.URL;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.util.ArrayList;

import javax.print.Doc;
import javax.print.DocFlavor;
import javax.print.DocPrintJob;
import javax.print.PrintService;
import javax.print.PrintServiceLookup;
import javax.print.SimpleDoc;
import javax.print.attribute.DocAttributeSet;
import javax.print.attribute.HashDocAttributeSet;
import javax.print.attribute.HashPrintRequestAttributeSet;
import javax.print.attribute.PrintRequestAttributeSet;
import javax.print.attribute.Size2DSyntax;
import javax.print.attribute.standard.Copies;
import javax.print.attribute.standard.MediaPrintableArea;
import javax.print.attribute.standard.MediaSize;
import javax.print.attribute.standard.OrientationRequested;
import javax.print.attribute.standard.PrinterIsAcceptingJobs;
import javax.print.attribute.standard.PrinterState;
import javax.print.attribute.standard.Sides;

import com.sun.pdfview.PDFFile;


public class PrintFile {
	@SuppressWarnings("unused")
	private static final long serialVersionUID = 1L;
	private static ArrayList<String> m_printfile;
	private static final String version = "11.15.12"; // MM.DD.YY - Date of last update

	/**
	 * 
	 * @param args -p<printer name> -o<LANDSCAPE/PORTRAIT> -s<ONE_SIDED/DUPLEX/TUMBLE> -c<# of Copies> -m<margin inch> -w<width inch> -h<height inch> -f<file1> -f<file2> ... -f<fileN>
	 */
	public static void main (String[] args) {
		System.out.println(version);
		if (args.length < 2){
			System.out.println("Error: Missing Parameters");
			System.out.println("java PrintFile -p<printer name> [-o<LANDSCAPE/PORTRAIT>] [-s<ONE_SIDED/DUPLEX/TUMBLE>] [-c<# of Copies>] [[-m<margin inch>] [-w<width inch>] [-h<height inch>]] -f<file1> -f<file2> ... -f<fileN>");
			System.exit(1);
		}
		// Defaults
		OrientationRequested orientation = OrientationRequested.PORTRAIT;
		Sides sides = Sides.ONE_SIDED;
		int numCopies = 1;
		float margins = (float) 0.5;
		float width = (float) 8.5;
		float height = (float) 11;
		String printerName = null;
		m_printfile = new ArrayList<String>();
		
		for (String arg : args){
			try {
				switch (arg.charAt(1)){
					case 'p': printerName = arg.substring(2); break;
					case 'o': if (arg.substring(2).equals("LANDSCAPE")) orientation = OrientationRequested.LANDSCAPE; break;
					case 's': if (arg.substring(2).equals("DUPLEX")) sides = Sides.DUPLEX;
								else if (arg.substring(2).equals("TUMBLE")) sides = Sides.TUMBLE;
								break;
					case 'c': numCopies = Integer.parseInt(arg.substring(2)); break;
					case 'm': margins = Float.parseFloat(arg.substring(2)); break;
					case 'w': width = Float.parseFloat(arg.substring(2)); break;
					case 'h': height = Float.parseFloat(arg.substring(2)); break;
					case 'f': m_printfile.add(arg.substring(2)); break;
				}
			} catch (NumberFormatException e) {
		        System.out.println("Error: Bad arguments");
		        System.exit(1);
		    }
			
		}

		printLabel(printerName, orientation, sides, numCopies, margins, width, height);
		System.out.println("DONE");
	}

	public static void printLabel(String printer_name, OrientationRequested orientation, Sides sides, int numCopies, float margins, float width, float height) {
		boolean allGood = false;
		
		for (int i = 0; i < m_printfile.size(); i++) {
			URL http;
			InputStream is = null;
//			FileInputStream pdfStream = null;
			PrintService service;
			allGood = false;
			DocFlavor myFormat = null;
			DocAttributeSet das = null;
			PrintRequestAttributeSet aset = new HashPrintRequestAttributeSet();
			String message = "";
//			PrinterJob pjob = null;
//			PDFPrintPage pages = null;
//			PDFFile pdfFile = null;
			
			String type = m_printfile.get(i).substring(m_printfile.get(0).length()-3).toLowerCase();
			if (type.equals("png")){
				myFormat = DocFlavor.INPUT_STREAM.PNG;
				das = new HashDocAttributeSet();
				das.add(new MediaPrintableArea((float)margins, 0, (float)width-(2*margins), (float)height/*-(2*margins)*/, MediaPrintableArea.INCH));
				aset.add(MediaSize.findMedia((float) width, (float) height, Size2DSyntax.INCH)); // Required for png format
			}
			else
				myFormat = DocFlavor.INPUT_STREAM.AUTOSENSE;
			aset.add(orientation);
			aset.add(sides);
			aset.add(new Copies(numCopies));
			service = PrintServiceLookup.lookupDefaultPrintService();	// Start by assuming we want default printer
			if (service != null && (printer_name == null || printer_name.trim().equals(""))){
				allGood = true;
			}
			else{
				PrintService[] services = PrintServiceLookup.lookupPrintServices(null, null);
				for (int j = 0; j < services.length; j++) {
					if (printer_name.trim().equalsIgnoreCase(services[j].getName().trim())) {
						service = services[j];
						allGood = true;
					}
				}
			}
			if (!allGood)
				System.out.println("Error: Printer <" + printer_name + "> not available or doesn't support file type.");
			else if (!service.getAttribute(PrinterIsAcceptingJobs.class).equals(PrinterIsAcceptingJobs.ACCEPTING_JOBS)) {
				PrinterState pState = service.getAttribute(PrinterState.class);
				System.out.println("Error: Printer <" + service.getName() + "> not accepting jobs.");
				allGood = false;
			}
			if (allGood) {
				DocPrintJob job = service.createPrintJob();
		//		pjob = PrinterJob.getPrinterJob();
				try {
					if (type.equals("pdf")){
						File f = new File(m_printfile.get(i));
						FileInputStream fis = new FileInputStream(f);
						FileChannel fc = fis.getChannel();
						ByteBuffer bb = fc.map(FileChannel.MapMode.READ_ONLY, 0, fc.size());
						PDFFile pdfFile = new PDFFile(bb);
						NativePDFPrint.print(pdfFile, service, orientation, sides, numCopies, margins, width, height, f.getName());
					}
					else{
						http = (new File(m_printfile.get(i))).toURI().toURL();
						is = http.openStream();
						Doc myDoc = new SimpleDoc(is, myFormat, das);
						job.print(myDoc, aset);
						is.close();
					}
				} catch (Exception ex) {
					String file = m_printfile.get(i).substring( (m_printfile.get(i).lastIndexOf(File.separator))+1 );
					System.out.println(file + " - "+ ex.getMessage());
					allGood = false;
				}
			}
		}
		if (!allGood){
			System.exit(1);
		}
	}

}
