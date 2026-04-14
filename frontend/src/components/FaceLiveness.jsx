import React, { useEffect, useRef } from "react";
import { FaceMesh } from "@mediapipe/face_mesh";
import { Camera } from "@mediapipe/camera_utils";

export default function FaceLiveness() {
  const videoRef = useRef(null);

  useEffect(() => {
    const faceMesh = new FaceMesh({
      locateFile: (file) =>
        `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`,
    });

    faceMesh.setOptions({
      maxNumFaces: 1,
      refineLandmarks: true,
      minDetectionConfidence: 0.7,
      minTrackingConfidence: 0.7,
    });

    faceMesh.onResults((results) => {
      if (results.multiFaceLandmarks.length > 0) {
        console.log("Face detected ✅");
      }
    });

    if (videoRef.current) {
      const camera = new Camera(videoRef.current, {
        onFrame: async () => {
          await faceMesh.send({ image: videoRef.current });
        },
        width: 640,
        height: 480,
      });
      camera.start();
    }
  }, []);

  return <video ref={videoRef} style={{ width: "100%" }} autoPlay />;
}