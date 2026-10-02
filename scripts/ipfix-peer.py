"""Read only the sender address of one UDP datagram; never print packet contents."""
import socket

with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as listener:
    listener.bind(('0.0.0.0', 2055))
    listener.settimeout(15)
    _, peer = listener.recvfrom(65535)
    print(peer[0])
