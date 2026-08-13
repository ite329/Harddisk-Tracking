(function () {
    'use strict';

    var config = window.DRUM_EXPORT_CONFIG || {};
    var rows = Array.isArray(config.rows) ? config.rows : [];
    var configuredRowsPerPage = Number(config.rowsPerPage || 0);
    var statusBox = document.getElementById('exportStatus');
    var statusText = document.getElementById('exportStatusText');
    var downloadButton = document.getElementById('downloadPdfBtn');
    var previewWrap = document.getElementById('previewWrap');
    var previewCanvas = document.getElementById('pdfPreviewCanvas');
    var generatedPdfBlob = null;
    var generationPromise = null;

    var PAGE_WIDTH = 1240;
    var PAGE_HEIGHT = 1754;
    var PDF_WIDTH = 595.28;
    var PDF_HEIGHT = 841.89;
    var FONT_FAMILY = '"Sarabun", Tahoma, Arial, sans-serif';

    var TABLE_Y = 184;
    var HEADER_TOP_HEIGHT = 42;
    var HEADER_SUB_HEIGHT = 56;
    var BODY_ROW_HEIGHT = 43;
    var TOTAL_ROW_HEIGHT = 44;
    var PAGE_BOTTOM_RESERVED = 394;

    function resolveRowsPerPage() {
        var headerHeight = HEADER_TOP_HEIGHT + HEADER_SUB_HEIGHT;
        var availableBodyHeight = PAGE_HEIGHT - TABLE_Y - headerHeight - TOTAL_ROW_HEIGHT - PAGE_BOTTOM_RESERVED;
        var automaticRowsPerPage = Math.max(1, Math.floor(availableBodyHeight / BODY_ROW_HEIGHT));

        if (Number.isFinite(configuredRowsPerPage) && configuredRowsPerPage > 0) {
            return Math.max(1, Math.min(Math.floor(configuredRowsPerPage), automaticRowsPerPage));
        }

        return automaticRowsPerPage;
    }

    var rowsPerPage = resolveRowsPerPage();

    function splitRowsIntoPages(dataRows) {
        var pages = [];
        if (!dataRows.length) {
            return [[]];
        }
        for (var start = 0; start < dataRows.length; start += rowsPerPage) {
            pages.push(dataRows.slice(start, start + rowsPerPage));
        }
        return pages;
    }

    function safeText(value) {
        return value === null || value === undefined ? '' : String(value).trim();
    }

    function setStatus(message, state) {
        if (statusText) {
            statusText.textContent = message;
        }
        if (statusBox) {
            statusBox.classList.toggle('done', state === 'done');
            statusBox.classList.toggle('error', state === 'error');
        }
    }

    function setFont(ctx, size, weight) {
        ctx.font = String(weight || 400) + ' ' + String(size) + 'px ' + FONT_FAMILY;
    }

    async function loadExportFonts() {
        if (!document.fonts || typeof document.fonts.load !== 'function') {
            return;
        }

        var fontSpecs = [
            '400 18px "Sarabun"',
            '500 18px "Sarabun"',
            '600 18px "Sarabun"',
            '700 18px "Sarabun"'
        ];
        var sampleText = 'ภาษาไทย Sarabun 0123456789';

        await Promise.all(fontSpecs.map(function (fontSpec) {
            return document.fonts.load(fontSpec, sampleText);
        }));
        await document.fonts.ready;

        var hasMissingFont = fontSpecs.some(function (fontSpec) {
            return !document.fonts.check(fontSpec, sampleText);
        });
        if (hasMissingFont) {
            throw new Error('ไม่พบฟอนต์ Sarabun กรุณาตรวจสอบไฟล์ในโฟลเดอร์ modules/drum_requests/fonts');
        }
    }

    function strokeCell(ctx, x, y, width, height, fillColor) {
        if (fillColor) {
            ctx.fillStyle = fillColor;
            ctx.fillRect(x, y, width, height);
        }
        ctx.strokeStyle = '#111111';
        ctx.lineWidth = 1.35;
        ctx.strokeRect(x, y, width, height);
    }

    function fitSingleLineFont(ctx, text, maxWidth, startSize, minSize, weight) {
        var size = startSize;
        while (size > minSize) {
            setFont(ctx, size, weight);
            if (ctx.measureText(text).width <= maxWidth) {
                break;
            }
            size -= 1;
        }
        return size;
    }

    function splitLongSegment(ctx, segment, maxWidth) {
        var lines = [];
        var current = '';
        Array.from(segment).forEach(function (character) {
            var test = current + character;
            if (current !== '' && ctx.measureText(test).width > maxWidth) {
                lines.push(current);
                current = character;
            } else {
                current = test;
            }
        });
        if (current !== '') {
            lines.push(current);
        }
        return lines;
    }

    function wrapText(ctx, text, maxWidth, maxLines) {
        text = safeText(text);
        if (text === '') {
            return [''];
        }

        var segments = text.split(/\s+/).filter(Boolean);
        if (segments.length <= 1) {
            segments = splitLongSegment(ctx, text, maxWidth);
        }

        var lines = [];
        var current = '';
        segments.forEach(function (segment) {
            var candidate = current === '' ? segment : current + ' ' + segment;
            if (current !== '' && ctx.measureText(candidate).width > maxWidth) {
                lines.push(current);
                current = segment;
            } else {
                current = candidate;
            }
        });
        if (current !== '') {
            lines.push(current);
        }

        if (lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            var lastIndex = lines.length - 1;
            var lastLine = lines[lastIndex];
            while (lastLine.length > 1 && ctx.measureText(lastLine + '...').width > maxWidth) {
                lastLine = lastLine.slice(0, -1);
            }
            lines[lastIndex] = lastLine + '...';
        }

        return lines;
    }

    function drawCellText(ctx, text, x, y, width, height, options) {
        options = options || {};
        var align = options.align || 'center';
        var weight = options.weight || 400;
        var fontSize = options.fontSize || 16;
        var minFontSize = options.minFontSize || 11;
        var maxLines = options.maxLines || 2;
        var padding = options.padding === undefined ? 8 : options.padding;
        var availableWidth = Math.max(8, width - (padding * 2));
        var value = safeText(text);
        var size = fontSize;
        var lines = [];

        while (size >= minFontSize) {
            setFont(ctx, size, weight);
            lines = wrapText(ctx, value, availableWidth, maxLines);
            var fits = lines.every(function (line) {
                return ctx.measureText(line).width <= availableWidth + 0.5;
            });
            if (fits) {
                break;
            }
            size -= 1;
        }

        setFont(ctx, size, weight);
        ctx.fillStyle = options.color || '#111111';
        ctx.textAlign = align;
        ctx.textBaseline = 'middle';

        var lineHeight = size * 1.28;
        var totalHeight = lines.length * lineHeight;
        var startY = y + (height / 2) - (totalHeight / 2) + (lineHeight / 2);
        var textX = x + (width / 2);
        if (align === 'left') {
            textX = x + padding;
        } else if (align === 'right') {
            textX = x + width - padding;
        }

        lines.forEach(function (line, index) {
            ctx.fillText(line, textX, startY + (index * lineHeight));
        });
    }

    function drawCenteredTitle(ctx, text, y, size, weight) {
        setFont(ctx, size, weight);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, PAGE_WIDTH / 2, y);
    }

    function drawMetadataLine(ctx, y, pageIndex, pageCount) {
        setFont(ctx, 18, 500);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';

        var requester = safeText(config.requester) || '................................';
        var department = safeText(config.department) || '-';
        var dateText = safeText(config.date) || '';

        ctx.fillText('ชื่อผู้เบิก', 82, y);
        ctx.strokeStyle = '#111111';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(170, y + 12);
        ctx.lineTo(395, y + 12);
        ctx.stroke();
        drawCellText(ctx, requester, 170, y - 14, 225, 28, { align: 'center', fontSize: 16, minFontSize: 12, maxLines: 1, weight: 500, padding: 4 });

        ctx.fillText('ฝ่าย/สังกัด', 415, y);
        drawCellText(ctx, department, 510, y - 16, 505, 32, { align: 'left', fontSize: 17, minFontSize: 12, maxLines: 1, weight: 500, padding: 0 });

        ctx.fillText('วันที่', 1015, y);
        ctx.strokeStyle = '#111111';
        ctx.beginPath();
        ctx.moveTo(1060, y + 12);
        ctx.lineTo(1160, y + 12);
        ctx.stroke();
        drawCellText(ctx, dateText, 1060, y - 14, 100, 28, { align: 'center', fontSize: 15, minFontSize: 12, maxLines: 1, weight: 500, padding: 2 });

        if (pageCount > 1) {
            setFont(ctx, 12, 500);
            ctx.textAlign = 'right';
            ctx.fillStyle = '#475569';
            ctx.fillText('หน้า ' + (pageIndex + 1) + '/' + pageCount, 1160, y - 23);
        }
    }

    function drawTableHeader(ctx, x0, y0, widths, topHeight, subHeight) {
        var headerColor = '#bfd7ed';
        var fullHeight = topHeight + subHeight;
        var x = x0;
        var firstHeaders = ['ลำดับ', 'รหัสสาขา', 'สาขาใหญ่', 'สาขาย่อย/ศูนย์บริการ', 'ศูนย์ต้นทุน'];

        firstHeaders.forEach(function (header, index) {
            strokeCell(ctx, x, y0, widths[index], fullHeight, headerColor);
            drawCellText(ctx, header, x, y0, widths[index], fullHeight, {
                fontSize: index === 3 ? 17 : 18,
                minFontSize: 13,
                maxLines: 2,
                weight: 700,
                padding: 5
            });
            x += widths[index];
        });

        var drumGroupWidth = widths[5] + widths[6];
        strokeCell(ctx, x, y0, drumGroupWidth, topHeight, headerColor);
        drawCellText(ctx, 'รายการเบิก', x, y0, drumGroupWidth, topHeight, {
            fontSize: 18,
            minFontSize: 14,
            maxLines: 1,
            weight: 700,
            padding: 4
        });

        strokeCell(ctx, x, y0 + topHeight, widths[6], subHeight, headerColor);
        drawCellText(ctx, 'Drum 56-59\nDR-3455', x, y0 + topHeight, widths[5], subHeight, {
            fontSize: 16,
            minFontSize: 13,
            maxLines: 2,
            weight: 600,
            padding: 4
        });
        x += widths[6];

        strokeCell(ctx, x, y0 + topHeight, widths[6], subHeight, headerColor);
        drawCellText(ctx, 'Drum 5915\nDR-3608', x, y0 + topHeight, widths[6], subHeight, {
            fontSize: 16,
            minFontSize: 13,
            maxLines: 2,
            weight: 600,
            padding: 4
        });
        x += widths[5];

        strokeCell(ctx, x, y0, widths[7], fullHeight, headerColor);
        drawCellText(ctx, 'ผู้แจ้ง', x, y0, widths[7], fullHeight, {
            fontSize: 18,
            minFontSize: 14,
            maxLines: 1,
            weight: 700,
            padding: 5
        });
    }

    function normalizeRow(row) {
        return {
            branchCode: safeText(row && row.branchCode),
            mainBranch: safeText(row && row.mainBranch),
            subBranch: safeText(row && row.subBranch),
            costCenter: safeText(row && row.costCenter),
            drum3455: Math.max(0, Math.floor(Number(row && row.drum3455) || 0)),
            drum3608: Math.max(0, Math.floor(Number(row && row.drum3608) || 0)),
            notifiedBy: safeText(row && row.notifiedBy)
        };
    }

    function calculateTotals(dataRows) {
        return dataRows.reduce(function (totals, row) {
            var normalized = normalizeRow(row);
            totals.drum3455 += normalized.drum3455;
            totals.drum3608 += normalized.drum3608;
            totals.total += normalized.drum3455 + normalized.drum3608;
            return totals;
        }, { drum3455: 0, drum3608: 0, total: 0 });
    }

    function drawDataRow(ctx, row, sequenceNumber, x0, y, widths, rowHeight) {
        var normalized = normalizeRow(row || {});
        var values = [
            row ? String(sequenceNumber) : '',
            normalized.branchCode,
            normalized.mainBranch,
            normalized.subBranch,
            normalized.costCenter,
            normalized.drum3455 ? String(normalized.drum3455) : '',
            normalized.drum3608 ? String(normalized.drum3608) : '',
            normalized.notifiedBy
        ];
        var x = x0;
        values.forEach(function (value, index) {
            strokeCell(ctx, x, y, widths[index], rowHeight, '#ffffff');
            drawCellText(ctx, value, x, y, widths[index], rowHeight, {
                align: (index === 3 || index === 7) ? 'left' : 'center',
                fontSize: index === 2 || index === 3 || index === 7 ? 14 : 15,
                minFontSize: index === 7 ? 9 : 10,
                maxLines: index === 7 ? 1 : 2,
                weight: (index === 0 || index === 1 || index === 4 || index === 5 || index === 6) ? 600 : 500,
                padding: 6
            });
            x += widths[index];
        });
    }

    function drawTotalsRow(ctx, x0, y, widths, height, totals, label) {
        var gray = '#c8c8c8';
        var firstMergedWidth = widths[0] + widths[1] + widths[2] + widths[3];
        strokeCell(ctx, x0, y, firstMergedWidth, height, gray);
        var x = x0 + firstMergedWidth;
        strokeCell(ctx, x, y, widths[4], height, gray);
        drawCellText(ctx, label, x, y, widths[4], height, {
            fontSize: 17,
            minFontSize: 13,
            maxLines: 1,
            weight: 700,
            padding: 4
        });
        x += widths[4];

        [totals.drum3455, totals.drum3608].forEach(function (value, index) {
            var widthIndex = index + 5;
            strokeCell(ctx, x, y, widths[widthIndex], height, gray);
            drawCellText(ctx, String(value), x, y, widths[widthIndex], height, {
                fontSize: 18,
                minFontSize: 14,
                maxLines: 1,
                weight: 500,
                padding: 4
            });
            x += widths[widthIndex];
        });

        // คอลัมน์ผู้แจ้งใช้แสดงชื่อเท่านั้น จึงไม่แสดงยอดรวมในแถวสรุป
        strokeCell(ctx, x, y, widths[7], height, gray);
    }

    function drawPrintedTimestamp(ctx, pageIndex, pageCount) {
        var printedAt = safeText(config.printedAt) || '-';
        var footerText = 'พิมพ์เอกสารเมื่อ ' + printedAt;
        if (pageCount > 1) {
            footerText += ' | หน้า ' + (pageIndex + 1) + '/' + pageCount;
        }

        setFont(ctx, 12, 400);
        ctx.fillStyle = '#64748b';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillText(footerText, PAGE_WIDTH - 72, PAGE_HEIGHT - 30);
    }

    function drawSignatureLine(ctx, label, x, y, lineWidth, dateText) {
        setFont(ctx, 18, 500);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, x, y);

        var labelWidth = ctx.measureText(label).width;
        var lineStartX = x + labelWidth + 8;
        var lineEndX = lineStartX + lineWidth;

        ctx.strokeStyle = '#111111';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(lineStartX, y + 11);
        ctx.lineTo(lineEndX, y + 11);
        ctx.stroke();

        var dateLabelX = lineEndX + 24;
        var dateValueX = dateLabelX + 58;
        var dateLineEndX = dateValueX + 112;
        var resolvedDate = safeText(dateText) || '-';

        setFont(ctx, 16, 500);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText('วันที่', dateLabelX, y);

        ctx.strokeStyle = '#111111';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(dateValueX, y + 11);
        ctx.lineTo(dateLineEndX, y + 11);
        ctx.stroke();

        drawCellText(ctx, resolvedDate, dateValueX, y - 14, dateLineEndX - dateValueX, 28, {
            align: 'center',
            fontSize: 15,
            minFontSize: 12,
            maxLines: 1,
            weight: 500,
            padding: 2
        });
    }

    function drawExportPage(pageRows, pageIndex, pageCount, globalTotals) {
        var canvas = document.createElement('canvas');
        canvas.width = PAGE_WIDTH;
        canvas.height = PAGE_HEIGHT;
        var ctx = canvas.getContext('2d', { alpha: false });
        ctx.imageSmoothingEnabled = true;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, PAGE_WIDTH, PAGE_HEIGHT);

        drawCenteredTitle(ctx, 'บริษัท เมืองไทย แคปปิตอล จำกัด (มหาชน)', 78, 23, 700);
        drawCenteredTitle(ctx, 'ใบเบิกทรัพย์สินสำนักงานใหญ่', 112, 22, 700);
        drawMetadataLine(ctx, 154, pageIndex, pageCount);

        var x0 = 72;
        var tableY = TABLE_Y;
        var widths = [58, 102, 210, 190, 130, 120, 120, 166];
        var headerTopHeight = HEADER_TOP_HEIGHT;
        var headerSubHeight = HEADER_SUB_HEIGHT;
        var headerHeight = headerTopHeight + headerSubHeight;
        var bodyRowHeight = BODY_ROW_HEIGHT;
        var totalHeight = TOTAL_ROW_HEIGHT;

        // ทุกหน้าวาดหัวตารางและโครงสร้างคอลัมน์เดิมซ้ำโดยอัตโนมัติ
        drawTableHeader(ctx, x0, tableY, widths, headerTopHeight, headerSubHeight);

        var bodyStartY = tableY + headerHeight;
        for (var rowIndex = 0; rowIndex < rowsPerPage; rowIndex += 1) {
            drawDataRow(ctx, pageRows[rowIndex] || null, (pageIndex * rowsPerPage) + rowIndex + 1, x0, bodyStartY + (rowIndex * bodyRowHeight), widths, bodyRowHeight);
        }

        var pageTotals = calculateTotals(pageRows);
        var isLastPage = pageIndex === pageCount - 1;
        var totals = isLastPage ? globalTotals : pageTotals;
        var totalLabel = isLastPage ? 'รวมทั้งหมด' : 'รวมหน้านี้';
        var totalsY = bodyStartY + (rowsPerPage * bodyRowHeight);
        drawTotalsRow(ctx, x0, totalsY, widths, totalHeight, totals, totalLabel);

        var noteY = totalsY + totalHeight + 48;
        setFont(ctx, 18, 500);
        ctx.fillStyle = '#111111';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText('หมายเหตุ : เซ็นรับของแล้วรบกวนสแกนให้ฝ่ายบัญชีสำนักงานใหญ่ด้วยครับ', 78, noteY);
        ctx.strokeStyle = '#111111';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(78, noteY + 12);
        ctx.lineTo(765, noteY + 12);
        ctx.stroke();

        var documentDate = safeText(config.date) || '-';
        drawSignatureLine(ctx, 'ผู้ขอเบิก(ฝ่ายไอที)', 160, noteY + 58, 250, documentDate);
        drawSignatureLine(ctx, 'ผู้อนุมัติ(ฝ่ายไอที)', 160, noteY + 112, 250, documentDate);
        drawSignatureLine(ctx, 'ผู้ส่งมอบทรัพย์สิน(ฝ่ายบัญชี)', 160, noteY + 166, 175, documentDate);
        drawPrintedTimestamp(ctx, pageIndex, pageCount);

        return canvas;
    }

    function canvasToJpegBytes(canvas) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (!blob) {
                    reject(new Error('ไม่สามารถแปลงหน้าเอกสารเป็นรูปภาพได้'));
                    return;
                }
                blob.arrayBuffer().then(function (buffer) {
                    resolve(new Uint8Array(buffer));
                }).catch(reject);
            }, 'image/jpeg', 0.93);
        });
    }

    function buildPdfBlob(imageBytesList) {
        var encoder = new TextEncoder();
        var chunks = [];
        var offsets = [];
        var currentOffset = 0;
        var pageCount = imageBytesList.length;
        var objectCount = 2 + (pageCount * 3);

        function pushBytes(bytes) {
            chunks.push(bytes);
            currentOffset += bytes.length;
        }

        function pushText(text) {
            pushBytes(encoder.encode(text));
        }

        function startObject(objectId) {
            offsets[objectId] = currentOffset;
            pushText(objectId + ' 0 obj\n');
        }

        function endObject() {
            pushText('endobj\n');
        }

        pushText('%PDF-1.4\n');
        pushBytes(new Uint8Array([0x25, 0xff, 0xff, 0xff, 0xff, 0x0a]));

        startObject(1);
        pushText('<< /Type /Catalog /Pages 2 0 R >>\n');
        endObject();

        var pageObjectIds = [];
        for (var pageIndex = 0; pageIndex < pageCount; pageIndex += 1) {
            pageObjectIds.push(5 + (pageIndex * 3));
        }

        startObject(2);
        pushText('<< /Type /Pages /Count ' + pageCount + ' /Kids [' + pageObjectIds.map(function (id) {
            return id + ' 0 R';
        }).join(' ') + '] >>\n');
        endObject();

        imageBytesList.forEach(function (imageBytes, pageIndex) {
            var imageObjectId = 3 + (pageIndex * 3);
            var contentObjectId = 4 + (pageIndex * 3);
            var pageObjectId = 5 + (pageIndex * 3);
            var imageName = 'Im' + pageIndex;

            startObject(imageObjectId);
            pushText('<< /Type /XObject /Subtype /Image /Width ' + PAGE_WIDTH + ' /Height ' + PAGE_HEIGHT + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + imageBytes.length + ' >>\nstream\n');
            pushBytes(imageBytes);
            pushText('\nendstream\n');
            endObject();

            var content = 'q\n' + PDF_WIDTH + ' 0 0 ' + PDF_HEIGHT + ' 0 0 cm\n/' + imageName + ' Do\nQ\n';
            var contentBytes = encoder.encode(content);
            startObject(contentObjectId);
            pushText('<< /Length ' + contentBytes.length + ' >>\nstream\n');
            pushBytes(contentBytes);
            pushText('endstream\n');
            endObject();

            startObject(pageObjectId);
            pushText('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PDF_WIDTH + ' ' + PDF_HEIGHT + '] /Resources << /XObject << /' + imageName + ' ' + imageObjectId + ' 0 R >> >> /Contents ' + contentObjectId + ' 0 R >>\n');
            endObject();
        });

        var xrefOffset = currentOffset;
        pushText('xref\n0 ' + (objectCount + 1) + '\n');
        pushText('0000000000 65535 f \n');
        for (var objectId = 1; objectId <= objectCount; objectId += 1) {
            var offset = offsets[objectId] || 0;
            pushText(String(offset).padStart(10, '0') + ' 00000 n \n');
        }
        pushText('trailer\n<< /Size ' + (objectCount + 1) + ' /Root 1 0 R >>\n');
        pushText('startxref\n' + xrefOffset + '\n%%EOF\n');

        return new Blob(chunks, { type: 'application/pdf' });
    }

    function downloadBlob(blob) {
        var filename = safeText(config.filename) || ('drum_withdrawals_' + new Date().toISOString().slice(0, 10) + '.pdf');
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 4000);
    }

    function copyCanvasToPreview(sourceCanvas) {
        if (!previewCanvas || !sourceCanvas) {
            return;
        }
        previewCanvas.width = sourceCanvas.width;
        previewCanvas.height = sourceCanvas.height;
        var previewContext = previewCanvas.getContext('2d', { alpha: false });
        previewContext.drawImage(sourceCanvas, 0, 0);
        if (previewWrap) {
            previewWrap.hidden = false;
        }
    }

    async function generatePdf() {
        if (generationPromise) {
            return generationPromise;
        }

        generationPromise = (async function () {
            try {
                setStatus('กำลังจัดหน้าและสร้างไฟล์ PDF...', 'working');
                await loadExportFonts();

                var pageRowsList = splitRowsIntoPages(rows);
                var pageCount = pageRowsList.length;
                var globalTotals = calculateTotals(rows);
                var canvases = [];
                for (var pageIndex = 0; pageIndex < pageCount; pageIndex += 1) {
                    canvases.push(drawExportPage(pageRowsList[pageIndex], pageIndex, pageCount, globalTotals));
                }

                copyCanvasToPreview(canvases[0]);

                var images = [];
                for (var imageIndex = 0; imageIndex < canvases.length; imageIndex += 1) {
                    setStatus('กำลังสร้างไฟล์ PDF หน้า ' + (imageIndex + 1) + '/' + canvases.length + '...', 'working');
                    images.push(await canvasToJpegBytes(canvases[imageIndex]));
                }

                generatedPdfBlob = buildPdfBlob(images);
                if (downloadButton) {
                    downloadButton.disabled = false;
                }
                setStatus('สร้างไฟล์ PDF สำเร็จ จำนวน ' + rows.length + ' รายการ (' + pageCount + ' หน้า) กดปุ่มดาวน์โหลด PDF เพื่อบันทึกไฟล์', 'done');
                return generatedPdfBlob;
            } catch (error) {
                console.error(error);
                setStatus(error && error.message ? error.message : 'ไม่สามารถสร้างไฟล์ PDF ได้', 'error');
                throw error;
            }
        })();

        return generationPromise;
    }

    if (downloadButton) {
        downloadButton.addEventListener('click', function () {
            if (generatedPdfBlob) {
                downloadBlob(generatedPdfBlob);
                return;
            }
            generatePdf().catch(function () {});
        });
    }

    generatePdf().catch(function () {});
})();
