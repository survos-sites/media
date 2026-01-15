# Add these to opencv-server.py

@app.route('/detect-codes', methods=['POST'])
def detect_codes():
    """Detect QR codes and barcodes in an image."""
    file = request.files['file']

    img_array = np.frombuffer(file.read(), np.uint8)
    img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    results = []

    # QR codes
    qr_detector = cv2.QRCodeDetector()
    data, points, _ = qr_detector.detectAndDecode(img)
    if data:
        results.append({
            'type': 'qr',
            'data': data,
            'points': points.tolist() if points is not None else None
        })

    # Multiple QR codes
    qr_multi = cv2.QRCodeDetector()
    retval, decoded_info, points, _ = qr_multi.detectAndDecodeMulti(img)
    if retval and decoded_info:
        for i, data in enumerate(decoded_info):
            if data and not any(r['data'] == data for r in results):
                results.append({
                    'type': 'qr',
                    'data': data,
                    'points': points[i].tolist() if points is not None else None
                })

    # Barcodes (requires opencv-contrib)
    try:
        barcode_detector = cv2.barcode.BarcodeDetector()
        ok, decoded, types, points = barcode_detector.detectAndDecodeMulti(img)
        if ok:
            for i, data in enumerate(decoded):
                if data:
                    results.append({
                        'type': types[i] if types else 'barcode',
                        'data': data,
                        'points': points[i].tolist() if points is not None else None
                    })
    except AttributeError:
        # opencv-contrib not installed, skip barcode
        pass

    return jsonify({'count': len(results), 'codes': results})


@app.route('/detect-codes-stream', methods=['POST'])
def detect_codes_stream():
    """
    For workflow mode: detect codes and return structured commands.
    Recognizes patterns like FOLDER:xxx, DOC:xxx, SKIP, END
    """
    file = request.files['file']

    img_array = np.frombuffer(file.read(), np.uint8)
    img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    qr_detector = cv2.QRCodeDetector()
    retval, decoded_info, points, _ = qr_detector.detectAndDecodeMulti(img)

    commands = []
    if retval and decoded_info:
        for data in decoded_info:
            if not data:
                continue

            # Parse known command patterns
            cmd = {'raw': data}
            if ':' in data:
                prefix, value = data.split(':', 1)
                prefix = prefix.upper()
                if prefix in ('FOLDER', 'DOCUMENT', 'DOC', 'DONOR', 'YEAR', 'DATE', 'COLLECTION', 'BOX'):
                    cmd['command'] = prefix
                    cmd['value'] = value
            elif data.upper() in ('SKIP', 'END', 'END_DOCUMENT', 'NEW_FOLDER', 'NEW_DOCUMENT'):
                cmd['command'] = data.upper()

            commands.append(cmd)

    return jsonify({
        'count': len(commands),
        'commands': commands,
        'has_workflow_commands': any('command' in c for c in commands)
    })
