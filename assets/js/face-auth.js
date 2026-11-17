document.querySelectorAll("[data-face-auth]").forEach((panel) => {
  const startButton = panel.querySelector("[data-face-start]");
  const message = panel.querySelector("[data-face-message]");
  const video = panel.querySelector("[data-face-video]");
  const csrfToken = panel.dataset.csrfToken;
  const endpoint = panel.dataset.endpoint;
  const mode = panel.dataset.mode;
  const challengeId = panel.dataset.challengeId;
  const turns = [panel.dataset.turnFirst, panel.dataset.turnSecond];
  const setupToken = panel.querySelector("[name='setup_token']");
  const recoveryToken = panel.querySelector("[name='recovery_token']");
  const consent = panel.querySelector("[name='biometric_consent']");

  if (!startButton || !message || !video || !endpoint || !csrfToken) {
    return;
  }

  let stream;
  let landmarker;

  const wait = (milliseconds) =>
    new Promise((resolve) => window.setTimeout(resolve, milliseconds));

  const setMessage = (text, isError = false) => {
    message.textContent = text;
    message.classList.toggle("text-danger", isError);
    message.classList.toggle("text-success", !isError);
  };

  const stopCamera = () => {
    stream?.getTracks().forEach((track) => track.stop());
    stream = undefined;
    video.srcObject = null;
  };

  const createLandmarker = async () => {
    const vision = await import(
      "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.32/+esm"
    );
    const fileset = await vision.FilesetResolver.forVisionTasks(
      "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.32/wasm",
    );
    return vision.FaceLandmarker.createFromOptions(fileset, {
      baseOptions: {
        modelAssetPath:
          "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
        delegate: "CPU",
      },
      runningMode: "VIDEO",
      numFaces: 2,
    });
  };

  const detectOneFace = () => {
    const result = landmarker.detectForVideo(video, performance.now());
    return result.faceLandmarks?.length === 1;
  };

  const captureFrame = () => {
    const canvas = document.createElement("canvas");
    canvas.width = 320;
    canvas.height = 240;
    const context = canvas.getContext("2d", { alpha: false });
    if (!context) {
      throw new Error("The browser could not capture a camera frame.");
    }
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", 0.88);
  };

  const captureSequence = async () => {
    const instructions = [
      "Look straight at the camera",
      `Turn your head ${turns[0]}`,
      "Look straight at the camera",
      `Turn your head ${turns[1]}`,
      "Look straight at the camera",
    ];
    const frames = [];

    for (const [index, instruction] of instructions.entries()) {
      setMessage(`Step ${index + 1} of 5: ${instruction}. Hold still.`);
      await wait(1400);
      if (!detectOneFace()) {
        throw new Error(
          "Keep one face clearly visible in the camera and start again.",
        );
      }
      frames.push(captureFrame());
    }

    setMessage("Checking the captured images securely...");
    const body = {
      csrf_token: csrfToken,
      challenge_id: challengeId,
      turns,
      frames,
    };
    if (panel.dataset.userId) {
      body.user_id = panel.dataset.userId;
    }
    if (setupToken) {
      body.setup_token = setupToken.value;
    }
    if (recoveryToken) {
      body.recovery_token = recoveryToken.value;
    }
    if (consent) {
      body.biometric_consent = consent.checked;
    }

    const response = await fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify(body),
    });
    const responseText = await response.text();
    let result;
    try {
      result = JSON.parse(responseText);
    } catch (error) {
      console.error("Face authentication returned non-JSON:", responseText);
      throw new Error(
        "The server returned an unexpected response. Check the PHP application logs and try again.",
      );
    }
    if (!response.ok || !result.ok) {
      throw new Error(result.message || "Face verification did not succeed.");
    }

    setMessage(result.message || "Face check complete.");
    window.location.assign(result.redirect || window.location.href);
  };

  startButton.addEventListener("click", async () => {
    startButton.disabled = true;
    try {
      if (
        mode === "enroll" &&
        (!consent?.checked ||
          (setupToken && setupToken.value.trim() === "") ||
          (recoveryToken && recoveryToken.value.trim() === ""))
      ) {
        throw new Error(
          "Confirm consent and complete the required setup field first.",
        );
      }
      setMessage("Requesting camera access...");
      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error("Camera access requires HTTPS or localhost in a supported browser.");
      }
      stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } },
      });
      video.srcObject = stream;
      await video.play();
      landmarker = await createLandmarker();
      await captureSequence();
    } catch (error) {
      console.error("Face authentication failed:", error);
      setMessage(
        error instanceof Error ? error.message : "Face authentication failed.",
        true,
      );
      startButton.disabled = false;
    } finally {
      stopCamera();
      landmarker?.close();
      landmarker = undefined;
    }
  });
});
