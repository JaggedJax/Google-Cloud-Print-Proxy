import java.awt.Graphics;
import java.awt.Graphics2D;
import java.awt.Rectangle;
import java.awt.print.PageFormat;
import java.awt.print.Printable;
import java.awt.print.PrinterException;

import javax.print.DocFlavor;
import javax.print.DocPrintJob;
import javax.print.PrintException;
import javax.print.PrintService;
import javax.print.SimpleDoc;
import javax.print.attribute.DocAttributeSet;
import javax.print.attribute.HashDocAttributeSet;
import javax.print.attribute.HashPrintRequestAttributeSet;
import javax.print.attribute.PrintRequestAttributeSet;
import javax.print.attribute.Size2DSyntax;
import javax.print.attribute.standard.Copies;
import javax.print.attribute.standard.JobName;
import javax.print.attribute.standard.MediaPrintableArea;
import javax.print.attribute.standard.MediaSize;
import javax.print.attribute.standard.MediaTray;
import javax.print.attribute.standard.OrientationRequested;
import javax.print.attribute.standard.Sides;

import com.sun.pdfview.PDFFile;
import com.sun.pdfview.PDFPage;
import com.sun.pdfview.PDFRenderer;

public class NativePDFPrint {

	static void print(final PDFFile pdfFile, PrintService service, final OrientationRequested orientation, Sides sides, int numCopies, float margins, float width, float height, String filename) throws PrinterException, PrintException {
		Printable pages = new Printable() {
			@Override
			public int print(Graphics g, PageFormat format, int index) throws PrinterException {
				if (index >= pdfFile.getNumPages()) {
					return Printable.NO_SUCH_PAGE;
				}
				Graphics2D g2 = (Graphics2D) g;
				PDFPage page = pdfFile.getPage(index+1);
				// no scaling, center PDF
				Rectangle bounds;
				float change = 1;
				if (orientation.equals(OrientationRequested.LANDSCAPE)){
					//	format.setOrientation(PageFormat.LANDSCAPE);
					change = .636f; // Bad landscape hack
				}
				/* fit the PDFPage into the printing area */
				if (format.getImageableWidth() >= page.getWidth() || format.getImageableHeight() >= page.getHeight()) {
					bounds = new Rectangle((int) Math.floor(format.getImageableX()), (int) Math.floor(format.getImageableY()),
							(int) Math.floor(page.getWidth()*change), (int) Math.floor(page.getHeight()));
					g2.translate(0, 0);
				}
				else{
					bounds = new Rectangle((int) Math.floor(format.getImageableX()), (int) Math.floor(format.getImageableY()),
							(int) Math.floor(format.getImageableWidth()*change), (int) Math.floor(format.getImageableHeight()));
					g2.translate(0, 0);
				}
				PDFRenderer pgs = new PDFRenderer(page, g2, bounds, null, null);
				try {
					page.waitForFinish();
					pgs.run();
				} catch (InterruptedException ie) {
					throw new PrinterException(ie.getMessage());
				}
				return PAGE_EXISTS;
			}
		};
		PrintService printService = service; // usual stuff to locate a PrintService
		DocPrintJob printJob = printService.createPrintJob();
		DocFlavor flavor = DocFlavor.SERVICE_FORMATTED.PRINTABLE;
		DocAttributeSet das = new HashDocAttributeSet();
		das.add(new MediaPrintableArea((float)margins, 0, (float)width-(2*margins), (float)height/*-(2*margins)*/, MediaPrintableArea.INCH));
		
		printJob.print(new SimpleDoc(pages, flavor, das), printRequestAttributeSet(orientation, sides, numCopies, width, height, filename));
	}

	private static PrintRequestAttributeSet printRequestAttributeSet(OrientationRequested orientation, Sides sides, int numCopies, float width, float height, String filename) {
		final PrintRequestAttributeSet aset = new HashPrintRequestAttributeSet();
		
		aset.add(new Copies(numCopies));
		aset.add(MediaTray.MAIN);
		//aset.add(MediaSizeName.NA_LETTER);
		aset.add(sides);
		aset.add(orientation);
		aset.add(MediaSize.findMedia(width, height, Size2DSyntax.INCH));
		aset.add(new JobName("Java: "+filename, null));
		return aset;
	}

}
