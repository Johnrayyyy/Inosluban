(function () {
  "use strict";

  if (!window.isSecureContext && location.hostname !== "localhost" && location.hostname !== "127.0.0.1") {
    document.querySelectorAll("[data-profile-camera]").forEach((button) => {
      button.title = "Camera capture needs HTTPS or localhost.";
    });
  }

  const modal = document.createElement("div");
  modal.className = "profile-camera-modal";
  modal.hidden = true;
  modal.setAttribute("aria-hidden", "true");
  modal.innerHTML = `
    <div class="profile-camera-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-camera-title">
      <header class="profile-camera-header">
        <h2 id="profile-camera-title">Take a photo</h2>
        <button type="button" class="profile-camera-close" data-camera-close aria-label="Close camera">&times;</button>
      </header>
      <div class="profile-camera-stage">
        <video data-camera-video autoplay muted playsinline></video>
        <div data-camera-guide class="profile-camera-guide face"></div>
      </div>
      <canvas data-camera-canvas hidden></canvas>
      <img data-camera-preview class="profile-camera-preview" alt="Captured photo preview" hidden>
      <p class="profile-camera-message" data-camera-message role="status"></p>
      <div class="profile-camera-actions-modal">
        <button type="button" class="btn btn-outline-secondary" data-camera-switch hidden>Switch camera</button>
        <button type="button" class="btn btn-primary" data-camera-capture>Capture</button>
        <button type="button" class="btn btn-outline-primary" data-camera-retake hidden>Retake</button>
        <button type="button" class="btn btn-primary" data-camera-use hidden>Use photo</button>
      </div>
    </div>`;
  document.body.appendChild(modal);

  const video = modal.querySelector("[data-camera-video]");
  const canvas = modal.querySelector("[data-camera-canvas]");
  const preview = modal.querySelector("[data-camera-preview]");
  const guide = modal.querySelector("[data-camera-guide]");
  const message = modal.querySelector("[data-camera-message]");
  const captureButton = modal.querySelector("[data-camera-capture]");
  const retakeButton = modal.querySelector("[data-camera-retake]");
  const useButton = modal.querySelector("[data-camera-use]");
  const switchButton = modal.querySelector("[data-camera-switch]");
  let stream = null;
  let targetInput = null;
  let preferredFacing = "environment";
  let devices = [];
  let deviceIndex = -1;
  let capturedFile = null;

  function setMessage(text, error) {
    message.textContent = text;
    message.classList.toggle("text-danger", Boolean(error));
  }

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
    }
    stream = null;
    video.srcObject = null;
  }

  function closeModal() {
    stopCamera();
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
  }

  function cameraError(error) {
    if (error && error.name === "NotAllowedError") {
      return "Camera permission was denied. Allow camera access in your browser settings and try again.";
    }
    if (error && error.name === "NotFoundError" || error && error.name === "DevicesNotFoundError") {
      return "No camera was found on this device.";
    }
    if (error && error.name === "NotReadableError" || error && error.name === "TrackStartError") {
      return "The camera is in use by another application. Close it there and try again.";
    }
    if (error && error.name === "OverconstrainedError") {
      return "The requested camera is unavailable.";
    }
    return "The camera could not be started.";
  }

  async function refreshDevices() {
    if (!navigator.mediaDevices?.enumerateDevices) return;
    devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === "videoinput");
  }

  async function startCamera(deviceId) {
    stopCamera();
    setMessage("Requesting camera access...");
    if (!window.isSecureContext && location.hostname !== "localhost" && location.hostname !== "127.0.0.1") {
      setMessage("Camera capture needs HTTPS or localhost.", true);
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      setMessage("Camera capture is unsupported in this browser.", true);
      return;
    }
    try {
      const videoConstraint = deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: { ideal: preferredFacing }, width: { ideal: 1280 }, height: { ideal: 960 } };
      stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraint });
      video.srcObject = stream;
      await video.play();
      await refreshDevices();
      switchButton.hidden = devices.length < 2;
      setMessage(preferredFacing === "user"
        ? "Center your face in the oval, then capture."
        : "Fill the frame with the ID card, then capture.");
    } catch (error) {
      setMessage(cameraError(error), true);
    }
  }

  async function openCamera(button) {
    targetInput = document.getElementById(button.dataset.profileCameraTarget);
    if (!targetInput) return;
    preferredFacing = button.dataset.profileCameraFacing === "user" ? "user" : "environment";
    guide.className = "profile-camera-guide " + (button.dataset.profileCameraShape === "face" ? "face" : "card");
    capturedFile = null;
    preview.hidden = true;
    canvas.hidden = true;
    video.hidden = false;
    captureButton.hidden = false;
    retakeButton.hidden = true;
    useButton.hidden = true;
    deviceIndex = -1;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    await startCamera();
  }

  function capturePhoto() {
    if (!stream || video.readyState < 2) {
      setMessage("The camera is not ready yet.", true);
      return;
    }
    const sourceWidth = video.videoWidth;
    const sourceHeight = video.videoHeight;
    if (!sourceWidth || !sourceHeight) {
      setMessage("The camera is not ready yet.", true);
      return;
    }
    const scale = Math.min(1, 1600 / Math.max(sourceWidth, sourceHeight));
    canvas.width = Math.max(1, Math.round(sourceWidth * scale));
    canvas.height = Math.max(1, Math.round(sourceHeight * scale));
    const context = canvas.getContext("2d", { alpha: false });
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    stopCamera();
    canvas.toBlob((blob) => {
      if (!blob) {
        setMessage("The photo could not be captured.", true);
        return;
      }
      capturedFile = new File([blob], "camera-capture-" + Date.now() + ".jpg", { type: "image/jpeg" });
      preview.src = URL.createObjectURL(capturedFile);
      preview.hidden = false;
      video.hidden = true;
      captureButton.hidden = true;
      retakeButton.hidden = false;
      useButton.hidden = false;
      setMessage("Preview your photo. Retake it or use it.");
    }, "image/jpeg", 0.9);
  }

  function retakePhoto() {
    capturedFile = null;
    preview.hidden = true;
    video.hidden = false;
    captureButton.hidden = false;
    retakeButton.hidden = true;
    useButton.hidden = true;
    startCamera(devices[deviceIndex]?.deviceId);
  }

  function usePhoto() {
    if (!capturedFile || !targetInput) return;
    if (typeof DataTransfer === "undefined") {
      setMessage("This browser cannot attach the captured photo. Please use Choose File.", true);
      return;
    }
    const transfer = new DataTransfer();
    transfer.items.add(capturedFile);
    targetInput.files = transfer.files;
    targetInput.dispatchEvent(new Event("change", { bubbles: true }));
    const thumbnail = document.querySelector(`[data-profile-camera-thumbnail="${targetInput.id}"]`);
    if (thumbnail) {
      thumbnail.src = URL.createObjectURL(capturedFile);
      thumbnail.hidden = false;
    }
    stopCamera();
    closeModal();
  }

  function readImage(file) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      const url = URL.createObjectURL(file);
      image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error("This image could not be opened. Choose a JPG or PNG photo."));
      };
      image.src = url;
    });
  }

  function canvasToJpeg(source, width, height, quality) {
    const output = document.createElement("canvas");
    output.width = width;
    output.height = height;
    output.getContext("2d", { alpha: false }).drawImage(source, 0, 0, width, height);
    return new Promise((resolve, reject) => {
      output.toBlob((blob) => {
        output.width = 0;
        output.height = 0;
        if (!blob) {
          reject(new Error("The photo could not be prepared. Choose another image and try again."));
          return;
        }
        resolve(blob);
      }, "image/jpeg", quality);
    });
  }

  async function prepareUpload(file) {
    if (!["image/jpeg", "image/png"].includes(file.type)) {
      throw new Error("Use JPG or PNG photos for both images.");
    }

    const image = await readImage(file);
    const width = image.naturalWidth;
    const height = image.naturalHeight;
    if (width < 160 || height < 120) {
      throw new Error("Each photo must be at least 160 × 120 pixels.");
    }

    const maxPixels = 12000000;
    const maxBytes = 5000000;
    if (file.size <= maxBytes && width * height <= maxPixels) {
      return file;
    }

    const scale = Math.min(1, 1600 / Math.max(width, height));
    const jpeg = await canvasToJpeg(
      image,
      Math.max(1, Math.round(width * scale)),
      Math.max(1, Math.round(height * scale)),
      0.86,
    );
    if (jpeg.size > maxBytes) {
      throw new Error("A photo is still too large after compression. Choose a smaller JPG or PNG.");
    }
    return new File([jpeg], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg" });
  }

  document.querySelectorAll("[data-profile-camera]").forEach((button) => {
    button.addEventListener("click", () => openCamera(button));
  });
  modal.querySelector("[data-camera-close]").addEventListener("click", closeModal);
  modal.addEventListener("click", (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) closeModal();
  });
  captureButton.addEventListener("click", capturePhoto);
  retakeButton.addEventListener("click", retakePhoto);
  useButton.addEventListener("click", usePhoto);
  switchButton.addEventListener("click", async () => {
    await refreshDevices();
    if (devices.length < 2) return;
    deviceIndex = (deviceIndex + 1) % devices.length;
    await startCamera(devices[deviceIndex].deviceId);
  });
  window.addEventListener("pagehide", stopCamera);

  const nationalForm = document.getElementById("national-id-profile-form");
  if (nationalForm) {
    const submitButton = document.getElementById("national-id-submit");
    submitButton.addEventListener("click", async () => {
      const status = nationalForm.querySelector("[data-national-id-status]");
      const format = nationalForm.querySelector('[name="id_format"]');
      const consent = nationalForm.querySelector('[name="national_id_consent"]');
      const front = nationalForm.querySelector('[name="front_image"]');
      const back = nationalForm.querySelector('[name="back_image"]');
      if (!format?.value) {
        status.textContent = "Choose a National ID format.";
        status.classList.add("text-danger");
        return;
      }
      if (!consent?.checked) {
        status.textContent = "Accept the terms and conditions first.";
        status.classList.add("text-danger");
        return;
      }
      if (!front?.files?.length || !back?.files?.length) {
        status.textContent = "Capture or choose the front and back/QR images of your National ID.";
        status.classList.add("text-danger");
        return;
      }
      status.classList.remove("text-danger", "text-success");
      submitButton.disabled = true;
      status.textContent = "Preparing photos and submitting securely...";
      try {
        const [frontFile, backFile] = await Promise.all([
          prepareUpload(front.files[0]),
          prepareUpload(back.files[0]),
        ]);
        const formData = new FormData();
        formData.append("csrf_token", nationalForm.querySelector('[name="csrf_token"]').value);
        formData.append("id_format", format.value);
        formData.append("national_id_consent", "accepted");
        formData.append("front_image", frontFile);
        formData.append("back_image", backFile);
        const response = await fetch("profile_id_verify.php", {
          method: "POST",
          credentials: "same-origin",
          body: formData,
          headers: { Accept: "application/json" },
        });
        const text = await response.text();
        let result;
        try {
          result = JSON.parse(text);
        } catch (error) {
          throw new Error("The server returned an unexpected response.");
        }
        if (!response.ok || !result.ok) {
          throw new Error(result.message || "The National ID pre-check could not be submitted.");
        }
        status.textContent = result.message || "Submission complete.";
        status.classList.add("text-success");
        window.setTimeout(() => window.location.reload(), 900);
      } catch (error) {
        status.textContent = error instanceof TypeError
          ? "Could not reach the verification service. Please try again shortly."
          : error instanceof Error
            ? error.message
            : "The National ID pre-check could not be submitted. Please try again.";
        status.classList.add("text-danger");
      } finally {
        submitButton.disabled = false;
      }
    });
  }
})();
