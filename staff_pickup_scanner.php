<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/document_pickup.php";
require_general_staff_or_admin();
ensure_staff_tables();
ensure_document_pickup_schema();

$pageTitle = "Scan pickup QR";
require __DIR__ . "/includes/header.php";
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png"
                alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA
                    CITY, BATANGAS</small><small>Staff Portal</small></span></a>
        <?php require __DIR__ . "/includes/staff_nav.php"; ?>
    </aside>
    <section class="dashboard-content">
        <?php require __DIR__ . "/includes/staff_topbar.php"; ?>
        <div class="staff-main">
            <div class="staff-hero compact">
                <div>
                    <p class="eyebrow">DOCUMENT PICKUP</p>
                    <h1>Scan resident QR code</h1>
                    <p>Scan the QR code from the resident's pickup email to complete the release.</p>
                </div>
            </div>
            <section class="staff-panel pickup-scanner-card">
                <div id="pickup-qr-reader" class="pickup-qr-reader"
                    aria-label="Camera QR code scanner"></div>
                <p id="pickup-qr-status" class="pickup-qr-status" role="status" aria-live="polite">
                    Allow camera access, then hold the resident's QR code inside the frame.</p>
                <button id="pickup-scan-next" class="btn btn-primary pickup-scan-next"
                    type="button" hidden>Scan next document</button>
                <form id="pickup-token-form" class="pickup-token-form">
                    <label class="form-label" for="pickup-token">Verification link or token</label>
                    <div class="input-group">
                        <input class="form-control" id="pickup-token" name="token"
                            placeholder="Paste the QR link if camera access is unavailable"
                            autocomplete="off">
                        <button class="btn btn-primary" type="submit">Verify</button>
                    </div>
                </form>
            </section>
        </div>
    </section>
</main>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
    onerror="window.pickupQrLibraryFailed = true"></script>
<script>
(() => {
    const readerId = "pickup-qr-reader";
    const status = document.getElementById("pickup-qr-status");
    const form = document.getElementById("pickup-token-form");
    const tokenInput = document.getElementById("pickup-token");
    const submitButton = form.querySelector("button[type='submit']");
    const nextButton = document.getElementById("pickup-scan-next");
    const csrfToken = <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>;
    const scanConfig = { fps: 10, qrbox: { width: 240, height: 240 } };
    let scanner = null;
    let scannerRunning = false;
    let state = "IDLE";
    let busy = false;
    let cameraStartPromise = null;
    let destroyed = false;

    const setStatus = (message, type = "") => {
        status.textContent = message;
        status.className = `pickup-qr-status${type ? ` is-${type}` : ""}`;
    };

    const validToken = (value) =>
        typeof value === "string" && /^[a-f0-9]{64}$/i.test(value.trim());

    const extractToken = (value) => {
        const input = String(value || "").trim();
        if (validToken(input)) {
            return input.toLowerCase();
        }
        try {
            const url = new URL(input, window.location.href);
            if (!/\/staff_verify_document\.php$/i.test(url.pathname)) {
                return "";
            }
            const token = url.searchParams.get("token") || "";
            return validToken(token) ? token.toLowerCase() : "";
        } catch {
            return "";
        }
    };

    const setBusy = (value) => {
        busy = value;
        submitButton.disabled = value;
        nextButton.disabled = value;
    };

    const stopScanner = async () => {
        if (!scanner) {
            return;
        }
        const activeScanner = scanner;
        scannerRunning = false;
        try {
            await activeScanner.stop();
        } catch (error) {
            if (state !== "SUCCESS") {
                console.warn("Pickup scanner stop failed.", error);
            }
        }
        try {
            await activeScanner.clear();
        } catch (error) {
            console.warn("Pickup scanner cleanup failed.", error);
        }
        if (scanner === activeScanner) {
            scanner = null;
        }
    };

    const startScanner = async () => {
        if (destroyed || busy || scannerRunning || cameraStartPromise) {
            return;
        }
        if (typeof Html5Qrcode === "undefined" || window.pickupQrLibraryFailed) {
            state = "ERROR";
            setStatus("The camera scanner could not be loaded. Use manual entry below.", "error");
            nextButton.hidden = false;
            return;
        }
        state = "STARTING";
        nextButton.hidden = true;
        setStatus("Starting camera. Camera access requires HTTPS or localhost and your permission.");
        cameraStartPromise = (async () => {
            try {
                await stopScanner();
                scanner = new Html5Qrcode(readerId);
                try {
                    await scanner.start(
                        { facingMode: "environment" },
                        scanConfig,
                        (decodedText) => verifyToken(decodedText),
                        () => {},
                    );
                } catch (environmentError) {
                    await stopScanner();
                    const cameras = await Html5Qrcode.getCameras();
                    if (!cameras || cameras.length === 0) {
                        throw environmentError;
                    }
                    const fallbackCamera = cameras.find((camera) =>
                        /back|rear|environment/i.test(camera.label || "")
                    ) || cameras[0];
                    scanner = new Html5Qrcode(readerId);
                    await scanner.start(
                        { deviceId: { exact: fallbackCamera.id } },
                        scanConfig,
                        (decodedText) => verifyToken(decodedText),
                        () => {},
                    );
                }
                if (destroyed) {
                    await stopScanner();
                    return;
                }
                scannerRunning = true;
                state = "SCANNING";
                setStatus("Camera ready. Hold the resident QR code inside the frame.");
            } catch (error) {
                state = "ERROR";
                await stopScanner();
                setStatus(
                    "Camera access is unavailable. Use manual entry below or allow camera permission and try again.",
                    "error",
                );
                nextButton.hidden = false;
            } finally {
                cameraStartPromise = null;
            }
        })();
        await cameraStartPromise;
    };

    const verifyToken = async (value) => {
        if (busy) {
            return;
        }
        const token = extractToken(value);
        if (!token) {
            setStatus("The scanned code is not a valid document pickup code.", "error");
            state = "ERROR";
            nextButton.hidden = false;
            return;
        }
        setBusy(true);
        state = "VERIFYING";
        await stopScanner();
        setStatus("Verifying pickup code...");
        try {
            const data = new FormData();
            data.set("csrf_token", csrfToken);
            data.set("token", token);
            const response = await fetch("staff_pickup_scan.php", {
                method: "POST",
                body: data,
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.message || "Pickup verification failed.");
            }
            state = "SUCCESS";
            setStatus(
                `Pickup verified for request ${result.request_number}. The document is now released.`,
                "success",
            );
            tokenInput.value = "";
            nextButton.hidden = false;
        } catch (error) {
            state = "ERROR";
            setStatus(
                error instanceof Error ? error.message : "Pickup verification failed.",
                "error",
            );
        } finally {
            setBusy(false);
            if (state === "ERROR") {
                nextButton.hidden = false;
            }
        }
    };

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        verifyToken(tokenInput.value);
    });

    nextButton.addEventListener("click", async () => {
        nextButton.hidden = true;
        state = "READY_FOR_NEXT_SCAN";
        await startScanner();
    });

    window.addEventListener("pagehide", () => {
        destroyed = true;
        void stopScanner();
    });

    void startScanner();
})();
</script>
<?php require __DIR__ . "/includes/footer.php"; ?>
