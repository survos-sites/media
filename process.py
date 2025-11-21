import cv2
import sys

image_path = sys.argv[1]
image = cv2.imread(image_path)
gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
cv2.imwrite('output.jpg', gray)
