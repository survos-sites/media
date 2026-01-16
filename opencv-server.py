from flask import Flask, request, jsonify
import cv2
import numpy as np
import requests

app = Flask(__name__)


@app.route("/", methods=["GET"])
def index():
    return jsonify({
        "service": "opencv-qr",
        "endpoints": [
            "GET /",
            "GET /health",
            "POST /detect-codes",
            "POST /detect-codes-stream"
        ]
    })


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})


@app.route("/detect-codes", methods=["POST"])
def detect_codes():
    img = None

    if "file" in request.files:
        file = request.files["file"]
        img_array = np.frombuffer(file.read(), np.uint8)
        img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    elif request.is_json and "url" in request.json:
        resp = requests.get(request.json["url"], timeout=10)
        resp.raise_for_status()
        img_array = np.frombuffer(resp.content, np.uint8)
        img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    if img is None:
        return jsonify({"error": "no image provided"}), 400

    results = []

    qr_detector = cv2.QRCodeDetector()

    # Single QR
    data, points, _ = qr_detector.detectAndDecode(img)
    if data:
        results.append({
            "type": "qr",
            "data": data,
            "points": points.tolist() if points is not None else None
        })

    # Multiple QR
    retval, decoded_info, points, _ = qr_detector.detectAndDecodeMulti(img)
    if retval and decoded_info:
        for i, data in enumerate(decoded_info):
            if data and not any(r["data"] == data for r in results):
                results.append({
                    "type": "qr",
                    "data": data,
                    "points": points[i].tolist() if points is not None else None
                })

    # Barcodes (opencv-contrib)
    try:
        barcode_detector = cv2.barcode.BarcodeDetector()
        ok, decoded, types, points = barcode_detector.detectAndDecodeMulti(img)
        if ok:
            for i, data in enumerate(decoded):
                if data:
                    results.append({
                        "type": types[i] if types else "barcode",
                        "data": data,
                        "points": points[i].tolist() if points is not None else None
                    })
    except Exception:
        pass

    return jsonify({
        "count": len(results),
        "codes": results
    })


@app.route("/detect-codes-stream", methods=["POST"])
def detect_codes_stream():
    if "file" not in request.files:
        return jsonify({"error": "file required"}), 400

    file = request.files["file"]
    img_array = np.frombuffer(file.read(), np.uint8)
    img = cv2.imdecode(img_array, cv2.IMREAD_COLOR)

    qr_detector = cv2.QRCodeDetector()
    retval, decoded_info, _, _ = qr_detector.detectAndDecodeMulti(img)

    commands = []
    if retval and decoded_info:
        for data in decoded_info:
            if not data:
                continue

            entry = {"raw": data}

            if ":" in data:
                prefix, value = data.split(":", 1)
                entry["command"] = prefix.upper()
                entry["value"] = value
            else:
                entry["command"] = data.upper()

            commands.append(entry)

    return jsonify({
        "count": len(commands),
        "commands": commands,
        "has_workflow_commands": any("command" in c for c in commands)
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8885)